<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Store;

use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Observation\WorkflowRunPickupProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Observation\WorkflowRunWaitProjectionInterface;

/**
 * The row an execution leaves behind, written by two hands.
 *
 * The **name** can only come from the metadata store: `ExecutionStarted` does not carry the workflow
 * type. The **outcome** can only come from the journal: the three abnormal endings all go through
 * the same `delete()` on the metadata side, and nothing there tells a cancellation from a failure.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/design.md
 * @see DUR030
 */
final class DbalWorkflowRunProjection implements WorkflowRunProjectionInterface, WorkflowRunPickupProjectionInterface, WorkflowRunWaitProjectionInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_workflow_runs',
    ) {}

    /**
     * An execution starts — or resumes under the same id.
     *
     * `started_at` is only written on insertion: the metadata store does an upsert, and rewriting
     * the date on every pass would make a long execution grow younger at every resume.
     */
    public function recordStart(string $executionId, string $workflowType): void
    {
        $this->schema->ensure();

        // Same trap as in the metadata store: the number of rows affected by an UPDATE does not
        // say the same thing on SQLite and on MySQL. Existence is asked for.
        $exists = false !== $this->connection->fetchOne(
            \sprintf('SELECT 1 FROM %s WHERE execution_id = ?', $this->table),
            [$executionId],
        );

        if ($exists) {
            $this->connection->update(
                $this->table,
                ['workflow_type' => $workflowType],
                ['execution_id' => $executionId],
            );
        } else {
            $this->connection->insert($this->table, [
                'execution_id' => $executionId,
                'workflow_type' => $workflowType,
                'status' => WorkflowRunStatus::Running->value,
                'started_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'ended_at' => null,
            ], [
                'started_at' => 'datetime_immutable',
                'ended_at' => 'datetime_immutable',
            ]);
        }
    }

    /**
     * A worker picked the execution up. Only the first pickup is kept, and a table created before
     * the column existed is left alone: a worker never fails on it.
     */
    public function recordPickup(string $executionId): void
    {
        $this->schema->ensure();
        if (!$this->schema->runsTableTracksPickup()) {
            return;
        }

        $this->connection->executeStatement(
            \sprintf('UPDATE %s SET picked_up_at = ? WHERE execution_id = ? AND picked_up_at IS NULL', $this->table),
            [new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $executionId],
            ['datetime_immutable'],
        );
    }

    /**
     * What the execution waits on, the latest one kept. Left alone on a table without the column.
     */
    public function recordWait(string $executionId, ?string $waitingOn): void
    {
        $this->schema->ensure();
        if (!$this->schema->runsTableTracksWait()) {
            return;
        }

        $this->connection->executeStatement(
            \sprintf('UPDATE %s SET waiting_on = ? WHERE execution_id = ?', $this->table),
            [$waitingOn, $executionId],
        );
    }

    /**
     * The execution has ended, in one of the four ways the journal knows how to speak of.
     *
     * No effect if no row exists: an execution whose start was not projected has no name, and a
     * row without a name would be worse than an absence.
     */
    public function recordOutcome(string $executionId, WorkflowRunStatus $status): void
    {
        $this->schema->ensure();

        $this->connection->update(
            $this->table,
            [
                'status' => $status->value,
                'ended_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ],
            ['execution_id' => $executionId],
            ['ended_at' => 'datetime_immutable'],
        );
    }
}
