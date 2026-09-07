<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Durable\Exception\ChildWorkflowStartDeferred;
use Gplanchat\Durable\Port\ChildWorkflowRunnerInterface;

/**
 * On the Temporal side, a child workflow is never run inline by the worker: the parent emits
 * COMMAND_TYPE_START_CHILD_WORKFLOW_EXECUTION and the server drives the rest, up to writing
 * CHILD_WORKFLOW_EXECUTION_COMPLETED / _FAILED into the parent's history — which
 * {@see TemporalExecutionHistory} already knows how to read back.
 *
 * Without this implementation, {@see \Gplanchat\Durable\ExecutionContext::executeChildWorkflow()}
 * raised a LogicException: child workflows were not usable on the Temporal driver, and the
 * command built by {@see TemporalWorkflowCommandBuffer::scheduleChildWorkflow()} was reached by
 * no caller.
 */
final class TemporalChildWorkflowRunner implements ChildWorkflowRunnerInterface
{
    public function defersChildStart(): bool
    {
        return true;
    }

    public function runChild(string $childExecutionId, string $workflowType, array $input, ?string $parentExecutionId = null): mixed
    {
        // The start command is already in the buffer; the awaitable stays unsettled until the
        // history carries the child's outcome.
        throw new ChildWorkflowStartDeferred();
    }
}
