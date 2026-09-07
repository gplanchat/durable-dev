<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\Port\WorkflowLifecycleInterface;

/**
 * Lifecycle outcomes of the Temporal backend: each one becomes a command of the current task,
 * pushed into {@see TemporalWorkflowCommandBuffer} then handed back to the server via
 * {@code RespondWorkflowTaskCompleted}.
 *
 * No method raises: a workflow task ends by returning its commands, not by propagating an
 * exception — that is the deep divergence from the in-memory backend.
 */
final readonly class TemporalWorkflowLifecycle implements WorkflowLifecycleInterface
{
    public function __construct(
        private TemporalWorkflowCommandBuffer $commandBuffer,
        /** Cause read from the history ({@see TemporalExecutionHistory::cancellationRequestedCause()}). */
        private ?string $cancellationRequestedCause = null,
        /** An earlier task already raised the cancellation in the fiber. */
        private bool $cancellationAlreadyDelivered = false,
    ) {}

    public function onBeforeRun(string $executionId): void
    {
        // Nothing to pre-empt: the cancellation is delivered in the fiber, at the wait point.
    }

    /**
     * Temporal cancellation is **cooperative**: the server only records
     * WORKFLOW_EXECUTION_CANCEL_REQUESTED and reschedules a workflow task. Answering it is up to
     * the worker — here by raising a {@see WorkflowCancelledFailure} in the fiber, then by
     * COMMAND_TYPE_CANCEL_WORKFLOW_EXECUTION if the handler does not swallow it.
     *
     * Temporal history cannot carry the *reason* of an operation cancellation: the delivery trace
     * therefore goes through a marker, which {@see TemporalExecutionHistory} reads back to reject
     * the same operations with the same exception on replay.
     */
    public function isCancellationPending(string $executionId): bool
    {
        return null !== $this->cancellationRequestedCause && !$this->cancellationAlreadyDelivered;
    }

    public function onCancellationDelivered(string $executionId, array $cancelledOperationIds): void
    {
        $this->commandBuffer->recordCancellationDelivered($cancelledOperationIds);
    }

    public function onCancelled(string $executionId, WorkflowCancelledFailure $failure): void
    {
        $this->commandBuffer->cancelWorkflow($this->cancellationRequestedCause ?? $failure->reason);
    }

    public function onCompleted(string $executionId, mixed $result): void
    {
        $this->commandBuffer->completeWorkflow($result);
    }

    public function onSuspended(string $executionId, Awaitable $pending): void
    {
        // The command is already in the buffer; the task ends by handing it back.
    }

    public function onContinuedAsNew(string $executionId, ContinueAsNewRequested $request): void
    {
        $this->commandBuffer->continueAsNew($request->workflowType, $request->payload, $request->options);
    }

    public function onFailed(string $executionId, \Throwable $failure): void
    {
        // A replay divergence is not a workflow failure: it is this attempt that cannot succeed.
        // Raising it propagates it up to the processor, which will answer
        // `RespondWorkflowTaskFailed` — no command, hence nothing in the history, hence an
        // execution that starts again as soon as the code that wrote it is put back.
        if ($failure instanceof WorkflowTaskFailure) {
            throw $failure;
        }

        $this->commandBuffer->failWorkflow($failure);
    }
}
