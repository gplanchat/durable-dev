<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate\Store;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Illuminate\Database\Connection;

/**
 * Which executions exist, and what became of them — on the Laravel side.
 *
 * Reading **and** writing in the same object, like the in-memory catalog and unlike the DBAL bridge
 * which separates `DbalWorkflowRunProjection` from its catalog. This is not a simplification: both
 * halves live on a single connection and a single table, and there is nothing to share between
 * them but a table name. The core decorators — {@see \Gplanchat\Durable\Store\ProjectingEventStore}
 * and {@see \Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore} — expect a
 * {@see WorkflowRunProjectionInterface} and therefore plug in knowing nothing about Illuminate.
 *
 * The cursor is a `(started_at, execution_id)` pair encoded in base64, exactly as on the DBAL side:
 * an offset would make the window slide on every concurrent insertion, and `started_at` alone
 * does not break ties within a burst of executions started in the same second. The conformance
 * suite creates its own in one go so as to fall precisely into that case.
 *
 * @see DUR037 observing a run is a projection
 * @see DUR041
 */
final class IlluminateWorkflowRunCatalog implements WorkflowRunCatalogInterface, WorkflowRunProjectionInterface
{
    private const BACKEND = 'Laravel database';

    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_workflow_runs',
        private readonly ?JournalRunHistoryReader $history = null,
    ) {}

    // -- write side: WorkflowRunProjectionInterface ----------------------------------------------

    public function recordStart(string $executionId, string $workflowType): void
    {
        $this->schema->ensure();

        $known = $this->connection->table($this->table)
            ->where('execution_id', $executionId)
            ->exists();

        if ($known) {
            // A continue-as-new rewrites the type without erasing the start date: the row keeps
            // its place in the order, and the cursor that designates it stays valid.
            $this->connection->table($this->table)
                ->where('execution_id', $executionId)
                ->update(['workflow_type' => $workflowType]);

            return;
        }

        $this->connection->table($this->table)->insert([
            'execution_id' => $executionId,
            'workflow_type' => $workflowType,
            'status' => WorkflowRunStatus::Running->value,
            'started_at' => self::now(),
            'ended_at' => null,
        ]);
    }

    public function recordOutcome(string $executionId, WorkflowRunStatus $status): void
    {
        $this->schema->ensure();

        $this->connection->table($this->table)
            ->where('execution_id', $executionId)
            ->update(['status' => $status->value, 'ended_at' => self::now()]);
    }

    // -- read side: WorkflowRunCatalogInterface --------------------------------------------------

    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20): WorkflowRunPage
    {
        $this->schema->ensure();
        $limit = max(1, $limit);

        $query = $this->connection->table($this->table)
            ->select(['execution_id', 'workflow_type', 'status', 'started_at', 'ended_at'])
            ->orderByDesc('started_at')
            ->orderBy('execution_id');

        if (null !== $status) {
            $query->where('status', $status->value);
        }

        $position = self::decodeCursor($cursor);
        if (null !== $position) {
            [$startedAt, $executionId] = $position;
            $query->where(function ($clause) use ($startedAt, $executionId): void {
                $clause->where('started_at', '<', $startedAt)
                    ->orWhere(function ($tie) use ($startedAt, $executionId): void {
                        $tie->where('started_at', $startedAt)
                            ->where('execution_id', '>', $executionId);
                    });
            });
        }

        // One row more than asked for: that row, and only it, says whether there is a next page.
        // Without it, an exactly full page would promise an empty one.
        $rows = $query->limit($limit + 1)->get()->all();
        $hasMore = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);

        $runs = [];
        foreach ($rows as $row) {
            $runs[] = new WorkflowRunDescription(
                runId: (string) $row->execution_id,
                workflowName: (string) $row->workflow_type,
                status: WorkflowRunStatus::from((string) $row->status),
                startedAt: self::toDateTime($row->started_at),
                endedAt: self::toDateTime($row->ended_at),
            );
        }

        $last = [] === $rows ? null : $rows[\array_key_last($rows)];

        return new WorkflowRunPage(
            $runs,
            $hasMore && null !== $last
                ? self::encodeCursor((string) $last->started_at, (string) $last->execution_id)
                : null,
        );
    }

    public function readHistory(WorkflowRunDescription $run): array
    {
        $reader = $this->history ?? new JournalRunHistoryReader(
            new IlluminateEventStore($this->connection, $this->schema),
        );

        return $reader->read($run->runId, $run->workflowName);
    }

    public function checkHealth(): BackendHealth
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            // We probe the connection, not the schema: going through `ensure()` would turn a
            // reachable but empty database into a failure.
            $this->connection->select('SELECT 1');
        } catch (\Throwable $failure) {
            return new BackendHealth(
                self::BACKEND,
                false,
                \sprintf('The database is unreachable: %s', $failure->getMessage()),
                $checkedAt,
            );
        }

        return new BackendHealth(self::BACKEND, true, 'The database answers.', $checkedAt);
    }

    // -------------------------------------------------------------------------------------------

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private static function encodeCursor(string $startedAt, string $executionId): string
    {
        return base64_encode($startedAt . "\0" . $executionId);
    }

    /**
     * @return array{string, string}|null
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
