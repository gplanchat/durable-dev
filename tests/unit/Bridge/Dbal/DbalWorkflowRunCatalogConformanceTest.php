<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowMetadataStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunProjection;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\ProjectingEventStore;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\Testing\WorkflowRunCatalogConformanceTestCase;

/**
 * The bootstrapping hooks write through the projecting stores, as a real worker would: the
 * catalog reads a projection, never the journal directly (DUR037).
 *
 * @see DUR041
 * @see DUR030
 */
final class DbalWorkflowRunCatalogConformanceTest extends WorkflowRunCatalogConformanceTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    protected function catalogUnderTest(): WorkflowRunCatalogInterface
    {
        return new DbalWorkflowRunCatalog($this->connection, $this->schema());
    }

    protected function startRun(string $executionId, string $workflowType): void
    {
        $this->metadataStore()->save($executionId, $workflowType, []);
        $this->eventStore()->append(new ExecutionStarted($executionId, []));
    }

    protected function endRun(string $executionId, WorkflowRunStatus $outcome): void
    {
        $this->eventStore()->append(match ($outcome) {
            WorkflowRunStatus::Completed => new ExecutionCompleted($executionId, 'ok'),
            WorkflowRunStatus::Cancelled => new WorkflowExecutionCancelled($executionId, 'annulé'),
            WorkflowRunStatus::ContinuedAsNew => new WorkflowContinuedAsNew($executionId, 'App\\NextWorkflow', []),
            WorkflowRunStatus::Failed => WorkflowExecutionFailed::fromStoredPayload($executionId, [
                'kind' => WorkflowExecutionFailed::KIND_WORKFLOW_HANDLER,
                'failureClass' => \RuntimeException::class,
                'failureMessage' => 'boom',
                'failureCode' => 0,
                'context' => [],
            ]),
            WorkflowRunStatus::Running => self::fail('Running is not an outcome'),
        });
    }

    protected function canTellAPickup(): bool
    {
        return true;
    }

    /**
     * What a dispatcher does: the metadata is saved, the resume is queued, nothing runs yet.
     */
    protected function dispatchRun(string $executionId, string $workflowType): void
    {
        $this->metadataStore()->save($executionId, $workflowType, []);
    }

    /**
     * What a worker does when it takes the run: the resume handler records the pickup.
     */
    protected function pickUp(string $executionId): void
    {
        $this->projection()->recordPickup($executionId);
    }

    private function schema(): DurableSchema
    {
        return new DurableSchema($this->connection);
    }

    private function projection(): DbalWorkflowRunProjection
    {
        return new DbalWorkflowRunProjection($this->connection, $this->schema());
    }

    private function metadataStore(): ProjectingWorkflowMetadataStore
    {
        return new ProjectingWorkflowMetadataStore(
            new DbalWorkflowMetadataStore($this->connection, $this->schema()),
            $this->projection(),
        );
    }

    private function eventStore(): ProjectingEventStore
    {
        return new ProjectingEventStore(
            new DbalEventStore($this->connection, $this->schema()),
            $this->projection(),
        );
    }
}
