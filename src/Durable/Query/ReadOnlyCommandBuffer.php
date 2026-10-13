<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Query;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\ReadOnlyPassRefusedWrite;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;

/**
 * The command buffer of a read-only pass: every command is refused, so that the pass appends no
 * event, enqueues no activity and starts no timer.
 *
 * @internal
 */
final class ReadOnlyCommandBuffer implements WorkflowCommandBufferInterface
{
    public function scheduleActivity(string $activityId, string $activityName, array $payload, ?ActivityOptions $options): void
    {
        throw new ReadOnlyPassRefusedWrite(__FUNCTION__);
    }

    public function startTimer(string $timerId, Duration $delay, string $summary): void
    {
        throw new ReadOnlyPassRefusedWrite(__FUNCTION__);
    }

    public function recordSideEffect(string $sideEffectId, mixed $result): void
    {
        throw new ReadOnlyPassRefusedWrite(__FUNCTION__);
    }

    public function recordUpdateHandled(string $updateName, array $arguments, mixed $result, ?FailureEnvelope $failure, ?string $updateId = null): void
    {
        throw new ReadOnlyPassRefusedWrite(__FUNCTION__);
    }

    public function scheduleChildWorkflow(ExecutionId $childExecutionId, string $childWorkflowType, array $input, ChildWorkflowOptions $options): void
    {
        throw new ReadOnlyPassRefusedWrite(__FUNCTION__);
    }

    public function completeWorkflow(mixed $result): void
    {
        throw new ReadOnlyPassRefusedWrite(__FUNCTION__);
    }

    public function completeChildWorkflow(ExecutionId $childExecutionId, mixed $result): void
    {
        throw new ReadOnlyPassRefusedWrite(__FUNCTION__);
    }

    public function failChildWorkflow(ExecutionId $childExecutionId, \Throwable $reason): void
    {
        throw new ReadOnlyPassRefusedWrite(__FUNCTION__);
    }

    public function recordVersion(string $changeId, int $version): void
    {
        throw new ReadOnlyPassRefusedWrite(__FUNCTION__);
    }

    public function failWorkflow(\Throwable $reason): void
    {
        throw new ReadOnlyPassRefusedWrite(__FUNCTION__);
    }

    public function scheduleNexusOperation(
        string $operationId,
        NexusEndpoint $endpoint,
        NexusService $service,
        NexusOperationName $operation,
        array $payload,
        NexusOperationTimeouts $timeouts,
        NexusOperationHeaders $headers,
    ): void {
        throw new ReadOnlyPassRefusedWrite(__FUNCTION__);
    }

    public function cancelNexusOperation(string $operationId, string $reason): void
    {
        throw new ReadOnlyPassRefusedWrite(__FUNCTION__);
    }

    public function cancelActivity(string $activityId, string $reason): void
    {
        throw new ReadOnlyPassRefusedWrite(__FUNCTION__);
    }

    public function cancelTimer(string $timerId, string $reason): void
    {
        throw new ReadOnlyPassRefusedWrite(__FUNCTION__);
    }
}
