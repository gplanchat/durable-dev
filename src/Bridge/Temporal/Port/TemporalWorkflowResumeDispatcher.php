<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Port;

use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Durable\Debug\WorkflowDispatchObserverInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

/**
 * Temporal-native implementation of WorkflowResumeDispatcher.
 *
 * dispatchNewWorkflowRun() calls WorkflowClientInterface::startAsync() (gRPC StartWorkflowExecution).
 * dispatchResume() is a no-op: after each workflow task Temporal itself re-schedules the next
 * PollWorkflowTaskQueue, so no application-level "resume" dispatch is needed.
 *
 * The optional dispatch observer is notified of each dispatch, so a debug surface (the Symfony
 * profiler's execution trace) can display Temporal-dispatched workflows: the Messenger middleware
 * only fires for dispatches that go through the bus.
 *
 * @see WorkflowClientInterface::startAsync()
 */
final class TemporalWorkflowResumeDispatcher implements WorkflowResumeDispatcher
{
    public function __construct(
        private readonly WorkflowClientInterface $workflowClient,
        private readonly WorkflowMetadataStore $metadataStore,
        private readonly WorkflowDefinitionLoader $workflowDefinitionLoader,
        private readonly ?WorkflowDispatchObserverInterface $executionTrace = null,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void
    {
        $this->metadataStore->save($executionId, $workflowType, $payload);

        $temporalType = $this->workflowDefinitionLoader->aliasForTemporalInterop($workflowType);
        $this->workflowClient->startAsync($temporalType, $payload, $executionId);

        $this->executionTrace?->onWorkflowDispatchRequested(
            $executionId,
            $workflowType,
            $payload,
            false,
            'temporal',
        );
    }

    /**
     * No-op: Temporal schedules the next workflow task automatically after each activity/timer
     * completion. There is no application-level message to dispatch.
     */
    public function dispatchResume(string $executionId, array $pendingUpdates = []): void {}
}
