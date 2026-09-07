<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Profiler;

use Gplanchat\Bridge\Temporal\Profiler\TemporalEventConverter;
use Gplanchat\Durable\Event\NexusOperationCancelled;
use Gplanchat\Durable\Event\NexusOperationCompleted;
use Gplanchat\Durable\Event\NexusOperationFailed;
use Gplanchat\Durable\Event\NexusOperationScheduled;
use Gplanchat\Durable\Event\NexusOperationTimedOut;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\NexusOperationCanceledEventAttributes;
use Temporal\Api\History\V1\NexusOperationCompletedEventAttributes;
use Temporal\Api\History\V1\NexusOperationFailedEventAttributes;
use Temporal\Api\History\V1\NexusOperationScheduledEventAttributes;
use Temporal\Api\History\V1\NexusOperationTimedOutEventAttributes;

/**
 * §4.4 — Nexus operations become visible to the profiler.
 *
 * Without conversion, an execution that calls an external service shows a hole: the Temporal
 * history carries the nine `NEXUS_OPERATION_*` events, the profiler sees none of them, and the
 * slowest call of a workflow is precisely the one nobody can look at.
 *
 * The terminal states are told apart because they do not read the same way: a failure calls for a
 * cause, a bound overrun calls for which bound, a cancellation calls for nothing.
 */
final class NexusEventConversionTest extends TestCase
{
    public function testASchedulingIsConvertedWithItsCallSite(): void
    {
        $attrs = new NexusOperationScheduledEventAttributes();
        $attrs->setEndpoint('paiements');
        $attrs->setService('facturation');
        $attrs->setOperation('encaisser');

        $event = $this->convert(EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED, 12, static function (HistoryEvent $e) use ($attrs): void {
            $e->setNexusOperationScheduledEventAttributes($attrs);
        });

        self::assertInstanceOf(NexusOperationScheduled::class, $event);
        self::assertSame('paiements', $event->endpoint());
        self::assertSame('facturation', $event->service());
        self::assertSame('encaisser', $event->operation());
        // The identity on the Temporal side is the eventId of the scheduling: it is through it
        // that the terminal events attach back to their operation.
        self::assertSame(12, $event->scheduledEventId());
    }

    public function testTheThreeTerminalStatesAreDistinguishable(): void
    {
        $completed = $this->convert(EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED, 20, static function (HistoryEvent $e): void {
            $a = new NexusOperationCompletedEventAttributes();
            $a->setScheduledEventId(12);
            $e->setNexusOperationCompletedEventAttributes($a);
        });
        $failed = $this->convert(EventType::EVENT_TYPE_NEXUS_OPERATION_FAILED, 21, static function (HistoryEvent $e): void {
            $a = new NexusOperationFailedEventAttributes();
            $a->setScheduledEventId(12);
            $e->setNexusOperationFailedEventAttributes($a);
        });
        $timedOut = $this->convert(EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT, 22, static function (HistoryEvent $e): void {
            $a = new NexusOperationTimedOutEventAttributes();
            $a->setScheduledEventId(12);
            $e->setNexusOperationTimedOutEventAttributes($a);
        });
        $cancelled = $this->convert(EventType::EVENT_TYPE_NEXUS_OPERATION_CANCELED, 23, static function (HistoryEvent $e): void {
            $a = new NexusOperationCanceledEventAttributes();
            $a->setScheduledEventId(12);
            $e->setNexusOperationCanceledEventAttributes($a);
        });

        self::assertInstanceOf(NexusOperationCompleted::class, $completed);
        self::assertInstanceOf(NexusOperationFailed::class, $failed);
        self::assertInstanceOf(NexusOperationTimedOut::class, $timedOut);
        self::assertInstanceOf(NexusOperationCancelled::class, $cancelled);
    }

    public function testEveryTerminalStateNamesTheOperationItCloses(): void
    {
        // The attachment is the only thing that lets the profiler recompose the life line of an
        // operation: without it, four floating events.
        foreach ([
            EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED,
            EventType::EVENT_TYPE_NEXUS_OPERATION_FAILED,
            EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT,
            EventType::EVENT_TYPE_NEXUS_OPERATION_CANCELED,
        ] as $type) {
            $event = $this->convert($type, 30, static function (HistoryEvent $e) use ($type): void {
                match ($type) {
                    EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED => $e->setNexusOperationCompletedEventAttributes((new NexusOperationCompletedEventAttributes())->setScheduledEventId(12)),
                    EventType::EVENT_TYPE_NEXUS_OPERATION_FAILED => $e->setNexusOperationFailedEventAttributes((new NexusOperationFailedEventAttributes())->setScheduledEventId(12)),
                    EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT => $e->setNexusOperationTimedOutEventAttributes((new NexusOperationTimedOutEventAttributes())->setScheduledEventId(12)),
                    default => $e->setNexusOperationCanceledEventAttributes((new NexusOperationCanceledEventAttributes())->setScheduledEventId(12)),
                };
            });

            self::assertNotNull($event, EventType::name($type) . ' not converted');
            self::assertSame(12, $event->scheduledEventId(), EventType::name($type) . ' does not name its operation');
        }
    }

    private function convert(int $type, int $eventId, callable $fill): ?object
    {
        $event = new HistoryEvent();
        $event->setEventType($type);
        $event->setEventId($eventId);
        $fill($event);

        return (new TemporalEventConverter('exec-nexus'))->convert($event);
    }
}
