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
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;

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
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->connection = $capsule->getConnection();
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
