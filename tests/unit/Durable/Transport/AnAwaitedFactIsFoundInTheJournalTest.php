<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Transport;

use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\AwaitedFact;
use PHPUnit\Framework\TestCase;

/**
 * DUR052: what a resume sent before its fact waits for, per kind of fact.
 */
final class AnAwaitedFactIsFoundInTheJournalTest extends TestCase
{
    public function testAnActivityFactIsItsTerminalOutcomeACancellationIncluded(): void
    {
        $journal = new InMemoryEventStore();
        self::assertFalse(AwaitedFact::activity('act-1')->isJournalledIn($journal, ExecutionId::fromString('exec-1')));

        $journal->append(new ActivityCancelled(ExecutionId::fromString('exec-1'), 'act-1', 'cancellation_requested'));

        self::assertTrue(AwaitedFact::activity('act-1')->isJournalledIn($journal, ExecutionId::fromString('exec-1')));
    }

    public function testAChildFactIsThatChildsOutcomeInTheParentsJournal(): void
    {
        $journal = new InMemoryEventStore();
        $journal->append(new ChildWorkflowCompleted(ExecutionId::fromString('parent-1'), ExecutionId::fromString('child-2'), 'done'));

        self::assertFalse(AwaitedFact::child('child-1')->isJournalledIn($journal, ExecutionId::fromString('parent-1')));
        self::assertTrue(AwaitedFact::child('child-2')->isJournalledIn($journal, ExecutionId::fromString('parent-1')));
    }

    /**
     * A named timer may be cancelled before it fires: a wait that took only TimerCompleted would
     * never end (DUR052 §2).
     */
    public function testATimersFactNeedsEachTimerCompletedOrCancelled(): void
    {
        $journal = new InMemoryEventStore();
        $journal->append(new TimerCompleted(ExecutionId::fromString('exec-1'), 'timer-1'));

        self::assertFalse(AwaitedFact::timers(['timer-1', 'timer-2'])->isJournalledIn($journal, ExecutionId::fromString('exec-1')));

        $journal->append(new TimerCancelled(ExecutionId::fromString('exec-1'), 'timer-2', 'superseded'));

        self::assertTrue(AwaitedFact::timers(['timer-1', 'timer-2'])->isJournalledIn($journal, ExecutionId::fromString('exec-1')));
    }
}
