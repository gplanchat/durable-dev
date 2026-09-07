<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Illuminate\Contracts\Queue\Factory as QueueFactory;

/**
 * The resume port, on Laravel's queue.
 *
 * The same shape as {@see \Gplanchat\Durable\Bundle\Messenger\MessengerWorkflowResumeDispatcher}: a
 * resume is a message, a new run first records its metadata then becomes one.
 *
 * **What the Symfony counterpart gets from a `DispatchAfterCurrentBusStamp`, this one gets from the
 * queue itself** — on one condition, and that is why the provider refuses the `sync` connection at
 * boot: on `sync`, `push()` runs the job on the spot, and a resume that dispatches another one
 * would recurse in the same process until the stack ends.
 */
final class LaravelWorkflowResumeDispatcher implements WorkflowResumeDispatcher
{
    public function __construct(
        private readonly QueueFactory $queue,
        private readonly WorkflowMetadataStore $metadataStore,
        private readonly ?string $connection = null,
        private readonly ?string $queueName = null,
    ) {}

    public function dispatchResume(string $executionId, array $pendingUpdates = []): void
    {
        $this->push(new ResumeWorkflowMessage($executionId, $pendingUpdates));
    }

    /** @param array<string, mixed> $payload */
    public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void
    {
        // The metadata first: a resume arriving before it would not know what to replay.
        $this->metadataStore->save($executionId, $workflowType, $payload);
        $this->push(new ResumeWorkflowMessage($executionId));
    }

    private function push(ResumeWorkflowMessage $message): void
    {
        $this->queue->connection($this->connection)->push(new ResumeWorkflowJob($message), '', $this->queueName);
    }
}
