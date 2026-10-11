<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ParentChildWorkflowCoordinator;
use Gplanchat\Durable\Query\WorkflowQueryEvaluator;
use Gplanchat\Durable\Store\ActivityEventJournal;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The static journal readers take an `ExecutionId` (#682); the string form stays accepted until the
 * next breaking release, and both read the same stream.
 */
final class TheJournalHelpersAcceptAnExecutionIdTest extends TestCase
{
    public function testTheActivityJournalReadsByExecutionId(): void
    {
        $store = new InMemoryEventStore();
        $id = ExecutionId::fromString('exec-1');
        $store->append(new ActivityCompleted($id, 'act-1', 'done'));

        self::assertTrue(ActivityEventJournal::hasTerminalOutcomeForActivity($store, $id, 'act-1'));
        self::assertTrue(ActivityEventJournal::hasTerminalOutcomeForActivity($store, 'exec-1', 'act-1'));
        self::assertSame(
            ActivityEventJournal::lastTerminalOutcome($store, 'exec-1', 'act-1'),
            ActivityEventJournal::lastTerminalOutcome($store, $id, 'act-1'),
        );
        self::assertSame(
            ActivityEventJournal::settledOutcomeForDelivery($store, 'exec-1', 'act-1', 1),
            ActivityEventJournal::settledOutcomeForDelivery($store, $id, 'act-1', 1),
        );
        self::assertFalse(ActivityEventJournal::hasActivityTaskFailedForAttempt($store, $id, 'act-1', 1));
        self::assertFalse(ActivityEventJournal::nextAttemptIsDue($store, $id, 'act-1', 1));
        self::assertFalse(ActivityEventJournal::hasActivityTaskStartedForAttempt($store, $id, 'act-1', 1));
    }

    public function testTheQueryEvaluatorReadsByExecutionId(): void
    {
        $store = new InMemoryEventStore();
        $id = ExecutionId::fromString('exec-1');
        $store->append(new TimerScheduled($id, 'timer-1', 900.0));

        self::assertTrue(WorkflowQueryEvaluator::hasPendingTimer($store, $id));
        self::assertNull(WorkflowQueryEvaluator::lastExecutionResult($store, $id));
    }

    public function testTheChildActivityCheckReadsByExecutionId(): void
    {
        $store = new InMemoryEventStore();
        $id = ExecutionId::fromString('exec-1');
        $store->append(new ExecutionStarted($id, []));

        self::assertTrue(ParentChildWorkflowCoordinator::isChildRunActive($store, $id));
        self::assertTrue(ParentChildWorkflowCoordinator::isChildRunActive($store, 'exec-1'));
    }
}
