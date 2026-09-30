<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Query;

use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Query\WorkflowQueryEvaluator;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * A timer is pending until it fires or is cancelled. `hasPendingTimer()` forgot the second case, so
 * a race loser or a cancelled `sleep()` kept an execution "waiting on a timer" for good, and the
 * Symfony bench's drain waited on it.
 */
final class ACancelledTimerIsNotPendingTest extends TestCase
{
    public function testAScheduledTimerIsPending(): void
    {
        $journal = new InMemoryEventStore();
        $journal->append(new TimerScheduled(ExecutionId::fromString('exec-1'), 'timer-1', 900.0));

        self::assertTrue(WorkflowQueryEvaluator::hasPendingTimer($journal, 'exec-1'));
    }

    public function testAFiredTimerIsNotPending(): void
    {
        $journal = new InMemoryEventStore();
        $journal->append(new TimerScheduled(ExecutionId::fromString('exec-1'), 'timer-1', 900.0));
        $journal->append(new TimerCompleted(ExecutionId::fromString('exec-1'), 'timer-1'));

        self::assertFalse(WorkflowQueryEvaluator::hasPendingTimer($journal, 'exec-1'));
    }

    public function testACancelledTimerIsNotPending(): void
    {
        $journal = new InMemoryEventStore();
        $journal->append(new TimerScheduled(ExecutionId::fromString('exec-1'), 'timer-1', 900.0));
        $journal->append(new TimerCancelled(ExecutionId::fromString('exec-1'), 'timer-1', 'race_superseded'));

        self::assertFalse(WorkflowQueryEvaluator::hasPendingTimer($journal, 'exec-1'));
    }
}
