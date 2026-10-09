<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Illuminate\Contracts\Queue\Factory as QueueFactory;

/**
 * The resume port, on Laravel's queue.
 *
 * The same shape as {@see \Gplanchat\Durable\Bundle\Messenger\MessengerWorkflowResumeDispatcher}: a
 * resume is a message, a new run first records its metadata then becomes one.
 *
 * **What the Symfony counterpart gets from a `DispatchAfterCurrentBusStamp`, this one gets from the
 * queue itself**, on one condition, and that is why the provider refuses the `sync` connection at
 * boot: on `sync`, `push()` runs the job on the spot, and a resume that dispatches another one
 * would recurse in the same process until the stack ends.
 */
final readonly class LaravelWorkflowResumeDispatcher implements WorkflowResumeDispatcher
{
    public function __construct(
        private readonly QueueFactory $queue,
        private readonly WorkflowMetadataStore $metadataStore,
        private readonly ?string $connection = null,
        private readonly ?string $queueName = null,
        /** A `sync` connection runs a job inline: an announcing resume would always run before its outcome. */
        private readonly bool $runsInline = false,
    ) {}

    public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void
    {
        $this->push(new ResumeWorkflowMessage($executionId->toString(), $pendingUpdates));
    }

    public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void
    {
        if (!$this->runsInline) {
            $this->push(new ResumeWorkflowMessage($executionId->toString(), [], $fact));
        }
    }

    /** @param array<string, mixed> $payload */
    public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        // A caller passing `::class` gets the alias: the name the journal, the dashboard and the
        // diagnose command all show (#258).
        $workflowType = (new WorkflowDefinitionLoader())->aliasForTemporalInterop($workflowType);
        // The metadata first: a resume arriving before it would not know what to replay.
        // Insert-only (#918): a row that exists is left as it is. Rewriting it would set `completed`
        // back to false and reopen a run that finished since the caller read it. The first send of a
        // run finds no row, and a run without one cannot complete.
        $this->metadataStore->insertIfAbsent($executionId, $workflowType, $payload);
        $this->push(new ResumeWorkflowMessage($executionId->toString()));
    }

    private function push(ResumeWorkflowMessage $message): void
    {
        $this->queue->connection($this->connection)->push(new ResumeWorkflowJob($message), '', $this->queueName);
    }
}
