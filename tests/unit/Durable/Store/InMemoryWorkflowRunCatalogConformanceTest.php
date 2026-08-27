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
 * La référence du port `WorkflowRunCatalogInterface`. Elle n'existait pas quand DUR041 a été écrit,
 * et son absence était le seul trou de la suite : un port dont un seul adaptateur prouvait quelque
 * chose.
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
            WorkflowRunStatus::Running => self::fail('Running n\'est pas une issue'),
        });
        $this->catalog->recordOutcome($executionId, $outcome);
    }
}
