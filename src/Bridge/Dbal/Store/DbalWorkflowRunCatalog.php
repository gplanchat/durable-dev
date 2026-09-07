<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Store;

use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;

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

        $position = self::decodeCursor($cursor);
        if (null !== $position) {
            [$startedAt, $executionId] = $position;
            $where[] = '(started_at < ? OR (started_at = ? AND execution_id > ?))';
            $params[] = $startedAt;
            $params[] = $startedAt;
            $params[] = $executionId;
        }

        // One row more than asked for: it, and it alone, tells whether there is a continuation.
        // Without it, an exactly full page would promise an empty page.
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT execution_id, workflow_type, status, started_at, ended_at FROM %s%s ORDER BY started_at DESC, execution_id ASC LIMIT %d',
                $this->table,
                [] === $where ? '' : ' WHERE ' . implode(' AND ', $where),
                $limit + 1,
            ),
            $params,
        );

        $hasMore = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);

        $runs = [];
        foreach ($rows as $row) {
            $runs[] = new WorkflowRunDescription(
                runId: (string) $row['execution_id'],
                workflowName: (string) $row['workflow_type'],
                status: WorkflowRunStatus::from((string) $row['status']),
                startedAt: self::toDateTime($row['started_at']),
                endedAt: self::toDateTime($row['ended_at']),
            );
        }

        $last = [] === $rows ? null : $rows[\array_key_last($rows)];

        return new WorkflowRunPage(
            $runs,
            $hasMore && null !== $last
                ? self::encodeCursor((string) $last['started_at'], (string) $last['execution_id'])
                : null,
        );
    }

    /**
     * @return list<WorkflowRunEvent>
     */
    public function readHistory(WorkflowRunDescription $run): array
    {
        $reader = $this->history ?? new JournalRunHistoryReader(
            new DbalEventStore($this->connection, $this->schema),
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

    private static function encodeCursor(string $startedAt, string $executionId): string
    {
        return base64_encode($startedAt . "\0" . $executionId);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function decodeCursor(?string $cursor): ?array
    {
        if (null === $cursor || '' === $cursor) {
            return null;
        }

        $raw = base64_decode($cursor, true);
        if (false === $raw || !str_contains($raw, "\0")) {
            return null;
        }

        [$startedAt, $executionId] = explode("\0", $raw, 2);

        return '' === $startedAt ? null : [$startedAt, $executionId];
    }

    private static function toDateTime(mixed $raw): ?\DateTimeImmutable
    {
        if ($raw instanceof \DateTimeImmutable) {
            return $raw;
        }
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        return new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
    }
}
