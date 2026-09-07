<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * An activity scheduled, started and then finished is **one action and three events**.
 *
 * A frieze filed by kind — "the activities", "the signals" — forced the operator to piece three
 * marks back together by eye to know how long that one had taken. The link did already exist in
 * the journal; it was the translation that threw it away.
 */
final class TheTimelineGroupsByActionTest extends TestCase
{
    public function testTheThreeEventsOfAnActivityAreOneAction(): void
    {
        $history = $this->read([
            new ActivityScheduled('exec-1', 'act-1', 'charge', [], []),
            new ActivityTaskStarted('exec-1', 'act-1', 'charge', 1),
            new ActivityCompleted('exec-1', 'act-1', ['receipt' => 'r-1']),
        ]);

        self::assertCount(1, array_unique(array_column($history, 'actionKey')));
        self::assertSame('activity:act-1', $history[0]->actionKey);
    }

    public function testTwoActivitiesAreTwoActions(): void
    {
        // The grouping must tell them apart, otherwise the frieze puts two unrelated waits on a
        // single row and passes their sum off as a duration.
        $history = $this->read([
            new ActivityScheduled('exec-1', 'act-1', 'charge', [], []),
            new ActivityScheduled('exec-1', 'act-2', 'notify', [], []),
        ]);

        self::assertNotSame($history[0]->actionKey, $history[1]->actionKey);
    }

    public function testATimerIsAnActionToo(): void
    {
        $history = $this->read([
            new TimerScheduled('exec-1', 'tim-1', 1700000000.0, 'avant relance'),
            new TimerCompleted('exec-1', 'tim-1'),
        ]);

        self::assertSame('timer:tim-1', $history[0]->actionKey);
        self::assertSame($history[0]->actionKey, $history[1]->actionKey);
    }

    public function testATimerIsNamedByItsSummaryAndNotByItsClass(): void
    {
        // "TimerScheduled" names the class, not the wait. A frieze row carries the name of its
        // action, and that is the one an operator came to read.
        $history = $this->read([
            new TimerScheduled('exec-1', 'tim-1', 1700000000.0, 'avant relance'),
            new TimerCompleted('exec-1', 'tim-1'),
        ]);

        self::assertSame('avant relance', $history[0]->label);
        self::assertSame('avant relance', $history[1]->label, 'the follow-up borrows the name of its scheduling');
    }

    public function testAnEventThatIsItsOwnActionSaysSoWithNull(): void
    {
        // `null` is an answer — "this event is its own action all by itself" — and that is what
        // lets the frieze give it its row without inventing a key.
        $history = $this->read([
            new WorkflowSignalReceived('exec-1', 'orderApproved', []),
        ]);

        self::assertNull($history[0]->actionKey);
    }

    public function testTheRunsOwnEventsAreTheFirstAction(): void
    {
        // A workflow task is not a business fact, it is the mechanism by which the engine moves
        // forward. One row per occurrence drowned the interesting actions under the plumbing.
        $history = $this->read([
            new ExecutionStarted('exec-1', []),
            new ActivityScheduled('exec-1', 'act-1', 'charge', [], []),
            new ActivityCompleted('exec-1', 'act-1', null),
            new ExecutionCompleted('exec-1', null),
        ], 'App\\OrderWorkflow');

        self::assertSame('workflow', $history[0]->actionKey);
        self::assertSame('workflow', $history[3]->actionKey, 'the end of the execution joins its start');
        self::assertSame('activity:act-1', $history[1]->actionKey);
    }

    public function testASignalIsNotPartOfTheRunsOwnAction(): void
    {
        // The trap is here: a received signal carries the same vocabulary as the execution. Filed
        // with it, it disappears into the first row instead of being the wait that it is.
        $history = $this->read([
            new ExecutionStarted('exec-1', []),
            new WorkflowSignalReceived('exec-1', 'orderApproved', []),
        ], 'App\\OrderWorkflow');

        self::assertSame('workflow', $history[0]->actionKey);
        self::assertNull($history[1]->actionKey);
    }

    public function testTheRunsLineIsNamedByTheWorkflowAndNotByAnEventClass(): void
    {
        // The journal only knows a stream: the name comes from the caller, which holds the
        // execution's description. Without it, the first row would be called "ExecutionStarted".
        $history = $this->read([new ExecutionStarted('exec-1', [])], 'App\\OrderWorkflow');

        self::assertSame('App\\OrderWorkflow', $history[0]->label);
    }

    public function testAChildWorkflowKeepsItsOwnLineAndItsOwnName(): void
    {
        $history = $this->read([
            new ExecutionStarted('exec-1', []),
            new ChildWorkflowScheduled('exec-1', 'child-1', 'App\\ShipmentWorkflow', []),
            new ChildWorkflowCompleted('exec-1', 'child-1', null),
        ], 'App\\OrderWorkflow');

        self::assertSame('child:child-1', $history[1]->actionKey);
        self::assertSame($history[1]->actionKey, $history[2]->actionKey);
        self::assertNotSame($history[0]->actionKey, $history[1]->actionKey, 'the child is not the parent');
        self::assertSame('App\\ShipmentWorkflow', $history[1]->label);
        self::assertSame('App\\ShipmentWorkflow', $history[2]->label, 'the follow-up borrows the name of its scheduling');
    }

    public function testTheStartOfTheWorkIsMarked(): void
    {
        // What precedes being picked up is not work, it is a queue. Without that fact, the frieze
        // draws two identical bars for "the worker took twenty seconds to answer" and "the
        // activity took twenty seconds to execute", and the operator facing a slow execution does
        // not know whether to look at their code or at their workers.
        $history = $this->read([
            new ActivityScheduled('exec-1', 'act-1', 'charge', [], []),
            new ActivityTaskStarted('exec-1', 'act-1', 'charge', 1),
            new ActivityCompleted('exec-1', 'act-1', ['receipt' => 'r-1']),
        ]);

        self::assertFalse($history[0]->started, 'scheduling is not starting');
        self::assertTrue($history[1]->started);
        self::assertFalse($history[2]->started, 'nor is finishing');
    }

    public function testATimerAnnouncesItsDelay(): void
    {
        // A summary says why we wait without saying how long, and it is the how long that the
        // operator comes to read. Working it out would ask them to subtract two timestamps from
        // two rows.
        $history = $this->read([
            new TimerScheduled('exec-1', 'tim-1', microtime(true) + 30.0, 'avant relance'),
        ]);

        self::assertSame('avant relance (30.0 s)', $history[0]->label);
    }

    public function testATimerWhoseDeadlineHasPassedAnnouncesNoDelayRatherThanFiftyYears(): void
    {
        // `scheduledAt()` is an **absolute deadline**, not a delay: subtracting without a guard
        // would have a timer whose deadline is behind us announce half a century of waiting — and
        // it is the same guard that covers the journal with no recording timestamp.
        $history = $this->read([
            new TimerScheduled('exec-1', 'tim-1', 1735689630.0, 'avant relance'),
        ]);

        self::assertSame('avant relance', $history[0]->label);
    }

    public function testAFailureIsMarkedAndACancellationIsNot(): void
    {
        // Red stops meaning anything as soon as it covers both: a failure is a breakdown, a
        // cancellation is an outcome somebody asked for.
        $history = $this->read([
            new ActivityScheduled('exec-1', 'act-1', 'charge', [], []),
            new ActivityTaskFailed('exec-1', 'act-1', 'charge', 1, 'RuntimeException', 'boom'),
            new ActivityCancelled('exec-1', 'act-1', 'cancellation_requested'),
        ]);

        self::assertFalse($history[0]->failed, 'scheduling fails at nothing');
        self::assertTrue($history[1]->failed);
        self::assertFalse($history[2]->failed, 'a cancellation is an outcome, not a failure');
    }

    /**
     * @param list<\Gplanchat\Durable\Event\Event> $events
     *
     * @return list<WorkflowRunEvent>
     */
    private function read(array $events, string $workflowName = ''): array
    {
        $store = new InMemoryEventStore();
        foreach ($events as $event) {
            $store->append($event);
        }

        return (new JournalRunHistoryReader($store))->read('exec-1', $workflowName);
    }
}
