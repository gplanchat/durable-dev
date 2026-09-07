<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\TaskQueue;
use Gplanchat\Durable\WorkflowIdReusePolicy;
use Gplanchat\Durable\WorkflowTimeouts;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\ParentClosePolicy as TemporalParentClosePolicy;
use Temporal\Api\Enums\V1\WorkflowIdReusePolicy as TemporalIdReusePolicy;

/**
 * A StartTimer without `start_to_fire_timeout` is rejected by the server, and a
 * StartChildWorkflowExecution without a ParentClosePolicy falls back on the server
 * default: in both cases the option chosen by the caller was silently lost.
 */
final class TemporalWorkflowCommandBufferSchedulingTest extends TestCase
{
    private function buffer(): TemporalWorkflowCommandBuffer
    {
        return new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), 'exec-1');
    }

    public function testStartTimerCarriesTheDelayItWasGiven(): void
    {
        // The port passes a delay, no longer a deadline: no clock subtraction here, and therefore
        // no more drift due to poll latency.
        $buffer = $this->buffer();
        $buffer->startTimer('timer-1', Duration::seconds(42), '');

        $attrs = $buffer->peek()[0]->getStartTimerCommandAttributes();
        self::assertNotNull($attrs);
        self::assertSame('timer-1', $attrs->getTimerId());
        $timeout = $attrs->getStartToFireTimeout();
        self::assertNotNull($timeout);
        self::assertSame(42, $timeout->getSeconds());
        self::assertSame(0, $timeout->getNanos());
    }

    public function testSubSecondDelaysSurviveTheConversion(): void
    {
        $buffer = $this->buffer();
        $buffer->startTimer('timer-ms', Duration::milliseconds(250), '');

        $timeout = $buffer->peek()[0]->getStartTimerCommandAttributes()?->getStartToFireTimeout();
        self::assertNotNull($timeout);
        self::assertSame(0, $timeout->getSeconds());
        self::assertSame(250_000_000, $timeout->getNanos());
    }

    public function testChildWorkflowCarriesParentCloseAndIdReusePolicies(): void
    {
        $options = new ChildWorkflowOptions(
            parentClosePolicy: ParentClosePolicy::Abandon,
            taskQueue: TaskQueue::named('dedicated-queue'),
            timeouts: WorkflowTimeouts::run(Duration::seconds(120.0)),
            workflowIdReusePolicy: WorkflowIdReusePolicy::RejectDuplicate,
        );

        $buffer = $this->buffer();
        $buffer->scheduleChildWorkflow('child-1', 'ChildType', ['a' => 1], $options);

        $attrs = $buffer->peek()[0]->getStartChildWorkflowExecutionCommandAttributes();
        self::assertNotNull($attrs);
        self::assertSame(TemporalParentClosePolicy::PARENT_CLOSE_POLICY_ABANDON, $attrs->getParentClosePolicy());
        self::assertSame(TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_REJECT_DUPLICATE, $attrs->getWorkflowIdReusePolicy());
        self::assertSame('dedicated-queue', $attrs->getTaskQueue()?->getName());
        self::assertSame(120, $attrs->getWorkflowRunTimeout()?->getSeconds());
    }

    public function testChildWorkflowDefaultsToTerminateOnTheConnectionQueue(): void
    {
        $buffer = $this->buffer();
        $buffer->scheduleChildWorkflow('child-2', 'ChildType', [], ChildWorkflowOptions::defaults());

        $attrs = $buffer->peek()[0]->getStartChildWorkflowExecutionCommandAttributes();
        self::assertNotNull($attrs);
        self::assertSame(TemporalParentClosePolicy::PARENT_CLOSE_POLICY_TERMINATE, $attrs->getParentClosePolicy());
        self::assertSame(TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_ALLOW_DUPLICATE_FAILED_ONLY, $attrs->getWorkflowIdReusePolicy());
    }
}
