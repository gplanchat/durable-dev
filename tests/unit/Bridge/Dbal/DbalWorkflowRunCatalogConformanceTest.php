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
use Gplanchat\Bridge\Dbal\Store\ProjectingEventStore;
use Gplanchat\Bridge\Dbal\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Testing\WorkflowRunCatalogConformanceTestCase;

/**
 * Les crochets d'amorçage écrivent par les stores projetants, comme le ferait un vrai worker : le
 * catalogue lit une projection, jamais le journal directement (DUR037).
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
            WorkflowRunStatus::Running => self::fail('Running n\'est pas une issue'),
        });
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
