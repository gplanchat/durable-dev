<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateEventStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowMetadataStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowRunCatalog;
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
use Illuminate\Database\Connection;
use unit\Bridge\SqlTestDatabase;

/**
 * The bootstrapping hooks write through the core decorators, as a real worker would: the catalog
 * reads a projection, never the journal directly (DUR037).
 *
 * And those decorators know nothing of Illuminate. They expect a
 * {@see \Gplanchat\Durable\Observation\WorkflowRunProjectionInterface}, which this catalog
 * implements by being its own projection — that is the only thing a third backend had to supply
 * to inherit the whole of the observability (DUR043).
 *
 * @see DUR041
 */
final class IlluminateWorkflowRunCatalogConformanceTest extends WorkflowRunCatalogConformanceTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqlTestDatabase::illuminate();
    }

    protected function catalogUnderTest(): WorkflowRunCatalogInterface
    {
        return $this->catalog();
    }

    protected function startRun(string $executionId, string $workflowType): void
    {
        $metadata = new ProjectingWorkflowMetadataStore(
            new IlluminateWorkflowMetadataStore($this->connection, $this->schema()),
            $this->catalog(),
        );
        $metadata->save($executionId, $workflowType, []);
        $this->journal()->append(new ExecutionStarted($executionId, []));
    }

    protected function endRun(string $executionId, WorkflowRunStatus $outcome): void
    {
        $this->journal()->append(match ($outcome) {
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

    protected function dispatchRun(string $executionId, string $workflowType): void
    {
        (new ProjectingWorkflowMetadataStore(
            new IlluminateWorkflowMetadataStore($this->connection, $this->schema()),
            $this->catalog(),
        ))->save($executionId, $workflowType, []);
    }

    protected function pickUp(string $executionId): void
    {
        // What the resume handler does when a worker takes the run.
        $this->catalog()->recordPickup($executionId);
    }

    protected function canTellAWait(): bool
    {
        return true;
    }

    protected function recordWait(string $executionId, string $waitingOn): void
    {
        $this->catalog()->recordWait($executionId, $waitingOn);
    }

    private function journal(): ProjectingEventStore
    {
        return new ProjectingEventStore(
            new IlluminateEventStore($this->connection, $this->schema()),
            $this->catalog(),
        );
    }

    private function catalog(): IlluminateWorkflowRunCatalog
    {
        return new IlluminateWorkflowRunCatalog($this->connection, $this->schema());
    }

    private function schema(): DurableSchema
    {
        return new DurableSchema($this->connection);
    }
}
