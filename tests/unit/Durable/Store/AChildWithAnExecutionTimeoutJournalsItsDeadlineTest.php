<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowDeadline;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Timer\PendingChildDeadlines;
use Gplanchat\Durable\Timer\VirtualClock;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\WorkflowTimeouts;
use PHPUnit\Framework\TestCase;

/**
 * The journal backends have no server to enforce a child's execution timeout: scheduling the
 * child writes the moment it must have ended by (#979).
 */
final class AChildWithAnExecutionTimeoutJournalsItsDeadlineTest extends TestCase
{
    public function testTheDeadlineIsTheSchedulingTimePlusTheExecutionTimeout(): void
    {
        [$store, $buffer] = $this->buffer(new VirtualClock(1000.0));

        $buffer->scheduleChildWorkflow(ExecutionId::fromString('child-1'), 'ChildType', [], new ChildWorkflowOptions(timeouts: new WorkflowTimeouts(execution: Duration::seconds(5))));

        $events = iterator_to_array($store->readStream(ExecutionId::fromString('parent-1')), false);
        self::assertInstanceOf(ChildWorkflowScheduled::class, $events[0]);
        self::assertInstanceOf(ChildWorkflowDeadline::class, $events[1]);
        self::assertSame(1005.0, $events[1]->dueAt());
        self::assertSame(['child-1' => 1005.0], PendingChildDeadlines::of($store, ExecutionId::fromString('parent-1')));
        self::assertSame([], PendingChildDeadlines::dueAt($store, ExecutionId::fromString('parent-1'), 1004.9));
        self::assertSame(['child-1'], PendingChildDeadlines::dueAt($store, ExecutionId::fromString('parent-1'), 1005.0));
    }

    public function testAChildWithoutAnExecutionTimeoutJournalsNoDeadline(): void
    {
        [$store, $buffer] = $this->buffer(new VirtualClock(1000.0));

        $buffer->scheduleChildWorkflow(ExecutionId::fromString('child-1'), 'ChildType', [], new ChildWorkflowOptions());

        self::assertCount(1, iterator_to_array($store->readStream(ExecutionId::fromString('parent-1')), false));
    }

    public function testAnOutcomeClosesTheDeadline(): void
    {
        [$store, $buffer] = $this->buffer(new VirtualClock(1000.0));
        $buffer->scheduleChildWorkflow(ExecutionId::fromString('child-1'), 'ChildType', [], new ChildWorkflowOptions(timeouts: new WorkflowTimeouts(execution: Duration::seconds(5))));

        $store->append(new ChildWorkflowCompleted(ExecutionId::fromString('parent-1'), ExecutionId::fromString('child-1'), null));

        self::assertSame([], PendingChildDeadlines::of($store, ExecutionId::fromString('parent-1')));
    }

    /**
     * @return array{InMemoryEventStore, EventStoreCommandBuffer}
     */
    private function buffer(VirtualClock $clock): array
    {
        $store = new InMemoryEventStore();

        return [$store, new EventStoreCommandBuffer($store, new NoopActivityTransport(), ExecutionId::fromString('parent-1'), $clock)];
    }
}
