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
 * §4.4 — les opérations Nexus deviennent visibles au profileur.
 *
 * Sans conversion, une exécution qui appelle un service externe montre un trou : l'historique
 * Temporal porte les neuf événements `NEXUS_OPERATION_*`, le profileur n'en voit aucun, et
 * l'appel le plus lent d'un workflow est justement celui qu'on ne peut pas regarder.
 *
 * Les états terminaux se distinguent parce qu'ils ne se lisent pas pareil : un échec appelle une
 * cause, un dépassement de borne appelle laquelle, une annulation n'appelle rien.
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
        // L'identité côté Temporal est l'eventId de la planification : c'est par lui que les
        // événements terminaux se rattachent à leur opération.
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
        // Le rattachement est la seule chose qui permette au profileur de recomposer la ligne de
        // vie d'une opération : sans lui, quatre événements flottants.
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

            self::assertNotNull($event, EventType::name($type) . ' non converti');
            self::assertSame(12, $event->scheduledEventId(), EventType::name($type) . ' ne nomme pas son opération');
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
