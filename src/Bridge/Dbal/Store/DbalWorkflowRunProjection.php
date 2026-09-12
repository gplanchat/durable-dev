<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Store;

use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunStatus;

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
final class DbalWorkflowRunProjection implements WorkflowRunProjectionInterface
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
