<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Testing\WorkflowRunCatalogConformanceTestCase;

/**
 * The reference for the `WorkflowRunCatalogInterface` port. It did not exist when DUR041 was
 * written, and its absence was the only hole in the suite: a port only one adapter proved anything
 * about.
 *
 * @see DUR041
 */
final class InMemoryWorkflowRunCatalogConformanceTest extends WorkflowRunCatalogConformanceTestCase
{
    private InMemoryEventStore $events;
    private InMemoryWorkflowRunCatalog $catalog;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->catalog = new InMemoryWorkflowRunCatalog($this->events);
    }

    protected function catalogUnderTest(): WorkflowRunCatalogInterface
    {
        return $this->catalog;
    }

    protected function startRun(string $executionId, string $workflowType): void
    {
        $this->catalog->recordStart($executionId, $workflowType);
        $this->events->append(new ExecutionStarted($executionId, []));
    }

    protected function endRun(string $executionId, WorkflowRunStatus $outcome): void
    {
        $this->events->append(match ($outcome) {
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
        $this->catalog->recordOutcome($executionId, $outcome);
    }
}
