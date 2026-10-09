<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Store;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\RunPageCursor;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunFilter;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\StoredTimestamp;
use Gplanchat\DurableModule\Schema\JournalSchema;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * The catalogue of executions, read from `durable_workflow_runs` on the journal's connection
 * (DUR056, #752). The counterpart of `DbalWorkflowRunCatalog`, and read-only: no DDL, no
 * transaction, the tables come from `durable:setup`.
 *
 * Paging is by key, as in the DBAL catalogue: the cursor carries the last `started_at` and
 * execution id read. `started_at` is a DATETIME(3), so the cursor carries milliseconds, as the
 * column returns them (`2026-10-05 10:00:00.250`), and the id breaks ties inside one millisecond.
 *
 * `groupId` stays absent, as on the DBAL backend: nothing groups the executions of a
 * continue-as-new chain.
 */
final readonly class MagentoWorkflowRunCatalog implements WorkflowRunCatalogInterface
{
    private const BACKEND = 'Magento journal database';

    public function __construct(
        private AdapterInterface $connection,
        private JournalSchema $schema,
        private JournalRunHistoryReader $history,
        private string $table = 'durable_workflow_runs',
    ) {}

    public function canFilterRuns(?WorkflowRunFilter $filter = null): bool
    {
        return true;
    }

    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20, ?WorkflowRunFilter $filter = null): WorkflowRunPage
    {
        $this->schema->assertInstalled();

        $limit = max(1, $limit);
        $where = [];
        $params = [];

        if (null !== $status) {
            $where[] = 'status = ?';
            $params[] = $status->value;
        }

        if (null !== $filter?->workflowName) {
            $where[] = 'CAST(workflow_type AS BINARY) = CAST(? AS BINARY)';
            $params[] = $filter->workflowName;
        }

        if (null !== $filter?->executionIdPrefix) {
            // Not a LIKE (its wildcards and its collation decide what matches), and not `=` on the
            // column's collation, which folds case and accents: bytes are compared.
            $where[] = \sprintf('CAST(LEFT(execution_id, %d) AS BINARY) = CAST(? AS BINARY)', $filter->executionIdPrefixLength());
            $params[] = $filter->executionIdPrefix;
        }

        $position = RunPageCursor::decode($cursor);
        if (null !== $position) {
            $where[] = '(started_at < ? OR (started_at = ? AND execution_id > ?))';
            array_push($params, $position->startedAt, $position->startedAt, $position->executionId);
        }

        // One row more than asked for tells whether a page follows.
        $rows = $this->select($where, $params, $limit + 1);

        $hasMore = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);
        $last = [] === $rows ? null : $rows[\array_key_last($rows)];

        return new WorkflowRunPage(
            array_map(self::describe(...), $rows),
            $hasMore && null !== $last
                ? (new RunPageCursor((string) $last['started_at'], (string) $last['execution_id']))->encode()
                : null,
            tellsWaitingForWorker: true,
        );
    }

    public function findRun(ExecutionId $executionId): ?WorkflowRunDescription
    {
        $this->schema->assertInstalled();
        $rows = $this->select(['execution_id = ?'], [$executionId->toString()], 1);

        return [] === $rows ? null : self::describe($rows[0]);
    }

    /**
     * @return list<WorkflowRunEvent>
     */
    public function readHistory(WorkflowRunDescription $run): array
    {
        return $this->history->read($run->runId, $run->workflowName);
    }

    public function checkHealth(): BackendHealth
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            // The connection is probed, not the schema: a reachable but empty database is not a failure.
            $this->connection->fetchOne('SELECT 1');
        } catch (\Throwable $failure) {
            return new BackendHealth(self::BACKEND, false, \sprintf('The journal database is unreachable: %s', $failure->getMessage()), $checkedAt);
        }

        return new BackendHealth(self::BACKEND, true, 'The journal database answers.', $checkedAt);
    }

    /**
     * @param list<string> $where
     * @param list<mixed>  $params
     *
     * @return list<array<string, mixed>>
     */
    private function select(array $where, array $params, int $limit): array
    {
        /** @var list<array<string, mixed>> */
        return $this->connection->fetchAll(
            \sprintf(
                'SELECT execution_id, workflow_type, status, started_at, ended_at, picked_up_at, waiting_on FROM %s%s ORDER BY started_at DESC, execution_id ASC LIMIT %d',
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
            waitingForWorkerSince: $status->isRunning() && null === $row['picked_up_at'] ? $startedAt : null,
            waitingOn: $status->isRunning() && null !== $row['waiting_on'] ? (string) $row['waiting_on'] : null,
        );
    }
}
