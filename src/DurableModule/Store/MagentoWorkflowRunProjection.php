<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Store;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunPickupProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Observation\WorkflowRunWaitProjectionInterface;
use Gplanchat\DurableModule\Schema\JournalSchema;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * The row of `durable_workflow_runs` on the journal's connection (DUR056, #733): start, pickup,
 * wait, outcome. The counterpart of `DbalWorkflowRunProjection`, with one difference: a run that
 * ends has no wait, so {@see recordOutcome()} sets `waiting_on` to null in the statement that sets
 * the status (the DBAL projection kept the last wait, #851).
 *
 * No DDL and no transaction of its own: the tables come from `durable:setup`, and the caller's
 * transaction, if any, is the only one.
 */
final readonly class MagentoWorkflowRunProjection implements WorkflowRunProjectionInterface, WorkflowRunPickupProjectionInterface, WorkflowRunWaitProjectionInterface
{
    public function __construct(
        private AdapterInterface $connection,
        private JournalSchema $schema,
        private string $table = 'durable_workflow_runs',
    ) {}

    /**
     * An execution starts, or resumes under the same id: one upsert, and `started_at` is only
     * written by the insertion, so a long execution does not grow younger at every resume.
     */
    public function recordStart(ExecutionId $executionId, string $workflowType): void
    {
        $this->schema->assertInstalled();

        $this->connection->insertOnDuplicate($this->table, [
            'execution_id' => $executionId->toString(),
            'workflow_type' => $workflowType,
            'status' => WorkflowRunStatus::Running->value,
            'started_at' => $this->now(),
        ], ['workflow_type']);
    }

    /**
     * A worker picked the execution up. Only the first pickup is kept.
     */
    public function recordPickup(ExecutionId $executionId): void
    {
        $this->schema->assertInstalled();

        $this->connection->update($this->table, ['picked_up_at' => $this->now()], [
            'execution_id = ?' => $executionId->toString(),
            'picked_up_at IS NULL',
        ]);
    }

    /**
     * What the execution waits on, the latest one kept.
     */
    public function recordWait(ExecutionId $executionId, ?string $waitingOn): void
    {
        $this->schema->assertInstalled();

        $this->connection->update($this->table, ['waiting_on' => $waitingOn], ['execution_id = ?' => $executionId->toString()]);
    }

    /**
     * The execution has ended. No effect if no row exists: a row without a workflow type would be
     * worse than an absence.
     */
    public function recordOutcome(ExecutionId $executionId, WorkflowRunStatus $status): void
    {
        $this->schema->assertInstalled();

        $this->connection->update(
            $this->table,
            ['status' => $status->value, 'ended_at' => $this->now(), 'waiting_on' => null],
            ['execution_id = ?' => $executionId->toString()],
        );
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
