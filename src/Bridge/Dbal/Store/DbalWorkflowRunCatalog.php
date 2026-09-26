<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Store;

use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\RunPageCursor;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\StoredTimestamp;

/**
 * The catalog of executions, read from the projection.
 *
 * Pagination is **by key**, not by offset. `started_at` is stored to the second and the table grows
 * while it is being read: an `OFFSET` would shift the window for every execution started between
 * two pages, and the operator would see rows twice or not at all. The cursor therefore carries the
 * last position read — date *and* id —, and the id breaks ties between executions of the same
 * second, which is the common case and not the edge case.
 *
 * `groupId` stays absent: the DBAL backend has no notion of grouping between the executions of a
 * single continue-as-new chain, and inventing one would be lying to the operator.
 *
 * @see DUR030
 */
final class DbalWorkflowRunCatalog implements WorkflowRunCatalogInterface
{
    private const BACKEND = 'SQL database';

    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_workflow_runs',
        private readonly ?JournalRunHistoryReader $history = null,
    ) {}

    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20): WorkflowRunPage
    {
        $this->schema->ensure();

        $limit = max(1, $limit);
        $where = [];
        $params = [];

        if (null !== $status) {
            $where[] = 'status = ?';
            $params[] = $status->value;
        }

        $position = RunPageCursor::decode($cursor);
        if (null !== $position) {
            [$startedAt, $executionId] = [$position->startedAt, $position->executionId];
            $where[] = '(started_at < ? OR (started_at = ? AND execution_id > ?))';
            $params[] = $startedAt;
            $params[] = $startedAt;
            $params[] = $executionId;
        }

        // One row more than asked for: it, and it alone, tells whether there is a continuation.
        // Without it, an exactly full page would promise an empty page.
        $rows = $this->select($where, $params, $limit + 1);

        $hasMore = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);
        $runs = array_map(self::describe(...), $rows);

        $last = [] === $rows ? null : $rows[\array_key_last($rows)];

        return new WorkflowRunPage(
            $runs,
            $hasMore && null !== $last
                ? (new RunPageCursor((string) $last['started_at'], (string) $last['execution_id']))->encode()
                : null,
            tellsWaitingForWorker: $this->schema->runsTableTracksPickup(),
        );
    }

    /**
     * @return list<WorkflowRunEvent>
     */
    public function readHistory(WorkflowRunDescription $run): array
    {
        $reader = $this->history ?? new JournalRunHistoryReader(
            new DbalEventStore($this->connection, $this->schema, $this->schema->eventsTable()),
        );

        return $reader->read($run->runId, $run->workflowName);
    }

    public function checkHealth(): BackendHealth
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            // The emptiest statement the dialect accepts: we probe the connection, not the schema.
            // Going through `ensure()` would turn a reachable but empty database into a failure.
            $this->connection->executeQuery($this->connection->getDatabasePlatform()->getDummySelectSQL());
        } catch (\Throwable $failure) {
            return new BackendHealth(
                self::BACKEND,
                false,
                \sprintf('The SQL database is unreachable: %s', $failure->getMessage()),
                $checkedAt,
            );
        }

        return new BackendHealth(self::BACKEND, true, 'The SQL database answers.', $checkedAt);
    }

    /**
     * @param list<string> $where
     * @param list<mixed>  $params
     *
     * @return list<array<string, mixed>>
     */
    private function select(array $where, array $params, int $limit): array
    {
        return $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT execution_id, workflow_type, status, started_at, ended_at%s FROM %s%s ORDER BY started_at DESC, execution_id ASC LIMIT %d',
                ($this->schema->runsTableTracksPickup() ? ', picked_up_at' : '') . ($this->schema->runsTableTracksWait() ? ', waiting_on' : ''),
                $this->table,
                [] === $where ? '' : ' WHERE ' . implode(' AND ', $where),
                $limit,
            ),
            $params,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function describe(array $row): WorkflowRunDescription
    {
        $status = WorkflowRunStatus::from((string) $row['status']);
        $startedAt = StoredTimestamp::toDateTime($row['started_at']);

        return new WorkflowRunDescription(
            runId: (string) $row['execution_id'],
            workflowName: (string) $row['workflow_type'],
            status: $status,
            startedAt: $startedAt,
            endedAt: StoredTimestamp::toDateTime($row['ended_at']),
            // Absent when the table cannot tell (#447): the column is then not selected at all.
            waitingForWorkerSince: \array_key_exists('picked_up_at', $row) && $status->isRunning() && null === $row['picked_up_at'] ? $startedAt : null,
            waitingOn: \array_key_exists('waiting_on', $row) && $status->isRunning() && null !== $row['waiting_on'] ? (string) $row['waiting_on'] : null,
        );
    }
}
