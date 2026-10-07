<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime\TableQueue;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

/**
 * Resumes on the `durable_resume` queue. The database backend always runs a worker apart from the
 * process that starts the run, so `dispatchResumeAwaiting()` always sends (the Laravel dispatcher
 * skips it only when its queue runs jobs inline).
 */
final readonly class TableQueueWorkflowResumeDispatcher implements WorkflowResumeDispatcher
{
    public function __construct(
        private TableQueue $queue,
        private WorkflowMetadataStore $metadata,
    ) {}

    public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void
    {
        $this->push(new ResumeWorkflowMessage($executionId->toString(), $pendingUpdates));
    }

    public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void
    {
        $this->push(new ResumeWorkflowMessage($executionId->toString(), [], $fact));
    }

    public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        $workflowType = (new WorkflowDefinitionLoader())->aliasForTemporalInterop($workflowType);
        // The metadata first: a resume that arrives before it would not know what to replay.
        // Insert-only (#918): a run that already has its row is not reopened.
        $this->metadata->insertIfAbsent($executionId, $workflowType, $payload);
        $this->push(new ResumeWorkflowMessage($executionId->toString()));
    }

    private function push(ResumeWorkflowMessage $message): void
    {
        $this->queue->enqueue(Queues::RESUME, Queues::encode($message));
    }
}
