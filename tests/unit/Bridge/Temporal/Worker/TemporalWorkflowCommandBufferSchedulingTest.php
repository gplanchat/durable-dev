<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Durable\TaskQueue;

use Gplanchat\Durable\Duration;

use Gplanchat\Durable\WorkflowTimeouts;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\WorkflowIdReusePolicy;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\ParentClosePolicy as TemporalParentClosePolicy;
use Temporal\Api\Enums\V1\WorkflowIdReusePolicy as TemporalIdReusePolicy;

/**
 * Un StartTimer sans `start_to_fire_timeout` est rejeté par le serveur, et un
 * StartChildWorkflowExecution sans ParentClosePolicy retombe sur le défaut serveur :
 * dans les deux cas l'option choisie par l'appelant était silencieusement perdue.
 */
final class TemporalWorkflowCommandBufferSchedulingTest extends TestCase
{
    private function buffer(): TemporalWorkflowCommandBuffer
    {
        return new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), 'exec-1');
    }

    public function testStartTimerCarriesTheRemainingDuration(): void
    {
        $buffer = $this->buffer();
        $buffer->startTimer('timer-1', microtime(true) + 42.0, '');

        $attrs = $buffer->peek()[0]->getStartTimerCommandAttributes();
        self::assertNotNull($attrs);
        self::assertSame('timer-1', $attrs->getTimerId());
        $timeout = $attrs->getStartToFireTimeout();
        self::assertNotNull($timeout);
        self::assertEqualsWithDelta(42.0, $timeout->getSeconds() + $timeout->getNanos() / 1_000_000_000, 1.0);
    }

    public function testStartTimerInThePastStaysStrictlyPositive(): void
    {
        $buffer = $this->buffer();
        $buffer->startTimer('timer-late', microtime(true) - 10.0, '');

        $timeout = $buffer->peek()[0]->getStartTimerCommandAttributes()?->getStartToFireTimeout();
        self::assertNotNull($timeout);
        self::assertGreaterThan(0, $timeout->getSeconds() * 1_000_000_000 + $timeout->getNanos());
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
        $buffer->scheduleChildWorkflow('child-1', 'ChildType', ['a' => 1], array_merge(
            ['parentClosePolicy' => $options->parentClosePolicy, 'workflowId' => null],
            $options->toSchedulingMetadata(),
        ));

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
        $buffer->scheduleChildWorkflow('child-2', 'ChildType', [], []);

        $attrs = $buffer->peek()[0]->getStartChildWorkflowExecutionCommandAttributes();
        self::assertNotNull($attrs);
        self::assertSame(TemporalParentClosePolicy::PARENT_CLOSE_POLICY_TERMINATE, $attrs->getParentClosePolicy());
        self::assertSame(TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_ALLOW_DUPLICATE_FAILED_ONLY, $attrs->getWorkflowIdReusePolicy());
    }
}
