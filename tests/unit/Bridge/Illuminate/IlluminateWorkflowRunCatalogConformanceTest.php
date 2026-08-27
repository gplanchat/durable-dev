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
 * Les crochets d'amorçage écrivent par les décorateurs du cœur, comme le ferait un vrai worker :
 * le catalogue lit une projection, jamais le journal directement (DUR037).
 *
 * Et ces décorateurs ne connaissent pas Illuminate. Ils attendent un
 * {@see \Gplanchat\Durable\Observation\WorkflowRunProjectionInterface}, que ce catalogue implémente
 * en étant sa propre projection — c'est la seule chose qu'un troisième backend a eu à fournir pour
 * hériter de toute l'observabilité (DUR043).
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
            WorkflowRunStatus::Running => self::fail('Running n\'est pas une issue'),
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
