<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Replay;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use PHPUnit\Framework\TestCase;

/**
 * The hole in the guard, pinned rather than filled — DUR042.
 *
 * The four other slot kinds carry an identity the history already records: the name of an
 * activity, the triplet of a Nexus operation, the type of a child. **A timer carries none.**
 * `TimerScheduled` keeps `clock() + delay`, an **absolute** deadline a replay cannot recompute,
 * and the original delay is recorded nowhere; `summary` is supplied by the author and defaults to
 * the empty string.
 *
 * This file exists so that the hole gets noticed. Without it, the first person to read the guard
 * would see an oversight there and "fix" it by comparing the deadline — which would make a
 * perfectly faithful replay diverge, since the deadline recomputed now is never the one from then.
 *
 * It also says what **bounds** the hole, and that is the more useful of the two: a slot shift goes
 * unnoticed only if it touches nothing but timers.
 */
final class TimerSlotHasNoIdentityTest extends TestCase
{
    private const EXECUTION = 'exec-timers';

    public function testAChangedDurationReplaysWithoutBeingReported(): void
    {
        // The behaviour as it is, and not as one would wish it: the slot resolves, no divergence
        // is reported. That is the hole.
        $store = new InMemoryEventStore();
        $store->append(new TimerScheduled(self::EXECUTION, 'timer-1', 1_000_000.0, ''));
        $store->append(new TimerCompleted(self::EXECUTION, 'timer-1'));

        $context = $this->context($store);
        $awaitable = $context->timer(Duration::seconds(3600.0));

        self::assertTrue($awaitable->isSettled(), 'The timer slot resolves: nothing to compare.');
    }

    public function testTheRecordedDeadlineIsAbsoluteAndSaysNothingAboutTheDelay(): void
    {
        // The reason for the hole, pinned: two timers of different durations, scheduled at
        // different instants, can carry the same deadline. The deadline therefore does not
        // identify the call — that is what forbids comparing it.
        $store = new InMemoryEventStore();
        $store->append(new TimerScheduled(self::EXECUTION, 'timer-1', 1_000_000.0, ''));

        $events = iterator_to_array($store->readStream(self::EXECUTION));
        $recorded = $events[0];

        self::assertInstanceOf(TimerScheduled::class, $recorded);
        self::assertSame(1_000_000.0, $recorded->scheduledAt(), 'The deadline is an instant, not a duration.');
        self::assertSame('', $recorded->summary(), 'And the only free field is optional.');
    }

    public function testAShiftThatAlsoMovesAnActivityIsStillCaught(): void
    {
        // What bounds the hole. A slot shift escapes the guard only if it touches **nothing but**
        // timers; as soon as an activity moves with it, the name catches it.
        $store = new InMemoryEventStore();
        $store->append(new TimerScheduled(self::EXECUTION, 'timer-1', 1_000_000.0, ''));
        $store->append(new TimerCompleted(self::EXECUTION, 'timer-1'));
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', 'chargeCard', []));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', 42));

        $context = $this->context($store);
        $context->timer(Duration::seconds(3600.0));

        $this->expectException(WorkflowTaskFailure::class);
        $context->activity('reserveStock', []);
    }

    private function context(InMemoryEventStore $store): ExecutionContext
    {
        return new ExecutionContext(
            self::EXECUTION,
            new EventStoreHistorySource($store, self::EXECUTION),
            new EventStoreCommandBuffer($store, new NoopActivityTransport(), self::EXECUTION),
        );
    }
}
