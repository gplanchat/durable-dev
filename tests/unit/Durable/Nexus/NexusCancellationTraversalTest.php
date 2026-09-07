<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\ActivityCancellationReason;
use Gplanchat\Durable\Awaitable\AnyAwaitable;
use Gplanchat\Durable\Awaitable\AwaitableCancellation;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Port\WorkflowHistorySourceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Cancelling a workflow reaches the Nexus operations in flight — §3.5.
 *
 * The task said "extend `WorkflowFiberDriver::cancelPending()`"; there is nothing there to extend.
 * The single traversal is {@see AwaitableCancellation}, which the driver and the composites share
 * since they were consolidated — its docblock tells why the two were merged: their two versions
 * did not descend to the same depth.
 */
final class NexusCancellationTraversalTest extends TestCase
{
    public function testAPendingOperationIsCancelled(): void
    {
        $buffer = $this->createMock(WorkflowCommandBufferInterface::class);
        $buffer->expects(self::once())
            ->method('cancelNexusOperation')
            ->with('op-en-vol', ActivityCancellationReason::WORKFLOW_CANCELLED);

        $context = $this->contextWith($buffer, 'op-en-vol');
        $pending = $this->schedule($context);

        self::assertSame(
            ['op-en-vol'],
            AwaitableCancellation::cancelUnsettled($context, $pending, ActivityCancellationReason::WORKFLOW_CANCELLED),
        );
    }

    public function testAnOperationBuriedInACompositeIsReachedToo(): void
    {
        // An operation under an `any()` bounded by a deadline would otherwise stay in flight
        // after the workflow is cancelled.
        $buffer = $this->createMock(WorkflowCommandBufferInterface::class);
        $buffer->expects(self::once())->method('cancelNexusOperation');

        $context = $this->contextWith($buffer, 'op-en-vol');
        $pending = $this->schedule($context);

        self::assertSame(
            ['op-en-vol'],
            AwaitableCancellation::cancelUnsettled($context, new AnyAwaitable([$pending]), ActivityCancellationReason::WORKFLOW_CANCELLED),
        );
    }

    public function testASettledOperationIsLeftAlone(): void
    {
        $buffer = $this->createMock(WorkflowCommandBufferInterface::class);
        $buffer->expects(self::never())->method('cancelNexusOperation');

        $history = $this->createStub(WorkflowHistorySourceInterface::class);
        $history->method('findScheduledNexusOperation')->willReturn('op-finie');
        $history->method('findNexusOperationSlotResult')->willReturn(['result' => 'ok', 'failed' => null]);

        $context = new ExecutionContext('nexus-1', $history, $buffer);

        self::assertSame(
            [],
            AwaitableCancellation::cancelUnsettled($context, $this->schedule($context), ActivityCancellationReason::WORKFLOW_CANCELLED),
        );
    }

    private function contextWith(WorkflowCommandBufferInterface $buffer, string $operationId): ExecutionContext
    {
        $history = $this->createStub(WorkflowHistorySourceInterface::class);
        $history->method('findScheduledNexusOperation')->willReturn($operationId);
        $history->method('findNexusOperationSlotResult')->willReturn(null);

        return new ExecutionContext('nexus-1', $history, $buffer);
    }

    private function schedule(ExecutionContext $context): \Gplanchat\Durable\Awaitable\Awaitable
    {
        return $context->nexusOperation(
            NexusEndpoint::named('payments'),
            NexusService::named('billing.v1.Billing'),
            NexusOperationName::named('charge'),
        );
    }
}
