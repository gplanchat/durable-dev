<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Awaitable\ActivityAwaitable;
use Gplanchat\Durable\Awaitable\AnyAwaitable;
use Gplanchat\Durable\Awaitable\ConditionAwaitable;
use Gplanchat\Durable\Awaitable\Deferred;
use Gplanchat\Durable\Awaitable\TimerAwaitable;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Observation\WaitReason;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\ProjectingEventStore;
use PHPUnit\Framework\TestCase;

/**
 * What a suspended run waits on, in words an operator reads on the run list (#324).
 */
final class WaitReasonTest extends TestCase
{
    public function testATimerSaysWhenItIsDue(): void
    {
        $store = new InMemoryEventStore();
        $store->append(new TimerScheduled('exec', 'timer-1', 1790244000.0, 'grace period'));

        self::assertSame(
            'timer "grace period" due at 2026-09-24T10:00:00+00:00',
            WaitReason::describe(new TimerAwaitable((new Deferred())->awaitable(), 'timer-1'), $store, 'exec'),
        );
    }

    public function testAnActivitySaysItsNameAndItsLatestAttempt(): void
    {
        $store = new InMemoryEventStore();
        $store->append(new ActivityScheduled('exec', 'act-1', 'charge', []));
        $activity = new ActivityAwaitable((new Deferred())->awaitable(), 'act-1');

        self::assertSame('activity charge', WaitReason::describe($activity, $store, 'exec'));

        $store->append(new ActivityTaskStarted('exec', 'act-1', 'charge', 1));
        $store->append(new ActivityTaskStarted('exec', 'act-1', 'charge', 2));
        self::assertSame('activity charge attempt 2 in flight', WaitReason::describe($activity, $store, 'exec'));
    }

    public function testAWaitWithADeadlineNamesTheConditionFirst(): void
    {
        $store = new InMemoryEventStore();
        $store->append(new TimerScheduled('exec', 'timer-1', 1790244000.0));
        $any = new AnyAwaitable([
            new ConditionAwaitable(static fn(): bool => false),
            new TimerAwaitable((new Deferred())->awaitable(), 'timer-1'),
        ]);

        self::assertStringStartsWith('condition at ' . __FILE__, (string) WaitReason::describe($any, $store, 'exec'));
    }

    public function testAnAttemptThatStartsRefreshesTheRunList(): void
    {
        $journal = new InMemoryEventStore();
        $catalog = new InMemoryWorkflowRunCatalog($journal);
        $store = new ProjectingEventStore($journal, $catalog);
        $catalog->recordStart('exec', 'App\\OrderWorkflow');
        $store->append(new ExecutionStarted('exec', []));

        $store->append(new ActivityTaskStarted('exec', 'act-1', 'charge', 2));

        $run = $catalog->listRuns()->runs[0];
        self::assertSame(WorkflowRunStatus::Running, $run->status);
        self::assertSame('activity charge attempt 2 in flight', $run->waitingOn);
    }
}
