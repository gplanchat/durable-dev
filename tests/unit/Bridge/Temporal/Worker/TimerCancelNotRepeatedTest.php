<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\ActivityCancellationReason;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\TimerCanceledEventAttributes;
use Temporal\Api\History\V1\TimerStartedEventAttributes;

/**
 * Une échéance réglée par l'autre branche fait annuler le minuteur perdant. Le replay repasse par
 * cette annulation à chaque reprise, et un minuteur annulé n'a pas de verdict à annoncer : il
 * revient en attente, et l'annulation est redemandée.
 *
 * Le journal SQL s'en gardait déjà — au pire un `TimerCancelled` en double. Le pont ne s'en
 * gardait pas, et Temporal ne pardonne pas : la tâche entière est rejetée
 * (`BadCancelTimerAttributes`), le worker meurt, la tâche est redélivrée, le worker meurt encore.
 * Une seule exécution empoisonnait toute la file.
 */
final class TimerCancelNotRepeatedTest extends TestCase
{
    private const TIMER = 'timer-1';

    public function testAnAlreadyCancelledTimerIsNotCancelledAgain(): void
    {
        $buffer = $this->bufferFor([$this->started(5), $this->cancelled(9)]);

        $buffer->cancelTimer(self::TIMER, ActivityCancellationReason::RACE_SUPERSEDED);

        self::assertSame([], $buffer->flush(), 'Une seconde annulation part au serveur et fait rejeter la tâche.');
    }

    public function testATimerThatAlreadyFiredIsNotCancelledEither(): void
    {
        $buffer = $this->bufferFor([$this->started(5), $this->fired(9)]);

        $buffer->cancelTimer(self::TIMER, ActivityCancellationReason::RACE_SUPERSEDED);

        self::assertSame([], $buffer->flush());
    }

    public function testATimerStillRunningIsCancelledOnce(): void
    {
        $buffer = $this->bufferFor([$this->started(5)]);

        $buffer->cancelTimer(self::TIMER, ActivityCancellationReason::RACE_SUPERSEDED);

        $commands = $buffer->flush();
        self::assertCount(1, $commands);
        self::assertSame(CommandType::COMMAND_TYPE_CANCEL_TIMER, $commands[0]->getCommandType());
        self::assertSame(self::TIMER, $commands[0]->getCancelTimerCommandAttributes()->getTimerId());
    }

    /**
     * @param list<HistoryEvent> $events
     */
    private function bufferFor(array $events): TemporalWorkflowCommandBuffer
    {
        return new TemporalWorkflowCommandBuffer(
            TemporalConnection::fromDsn('temporal://127.0.0.1:7233?namespace=default&tls=0'),
            'exec-1',
            TemporalExecutionHistory::fromEvents($events),
        );
    }

    private function started(int $eventId): HistoryEvent
    {
        $attrs = new TimerStartedEventAttributes();
        $attrs->setTimerId(self::TIMER);

        $event = new HistoryEvent();
        $event->setEventId($eventId);
        $event->setEventType(EventType::EVENT_TYPE_TIMER_STARTED);
        $event->setTimerStartedEventAttributes($attrs);

        return $event;
    }

    private function cancelled(int $eventId): HistoryEvent
    {
        $attrs = new TimerCanceledEventAttributes();
        $attrs->setTimerId(self::TIMER);

        $event = new HistoryEvent();
        $event->setEventId($eventId);
        $event->setEventType(EventType::EVENT_TYPE_TIMER_CANCELED);
        $event->setTimerCanceledEventAttributes($attrs);

        return $event;
    }

    private function fired(int $eventId): HistoryEvent
    {
        $attrs = new \Temporal\Api\History\V1\TimerFiredEventAttributes();
        $attrs->setStartedEventId(5);
        $attrs->setTimerId(self::TIMER);

        $event = new HistoryEvent();
        $event->setEventId($eventId);
        $event->setEventType(EventType::EVENT_TYPE_TIMER_FIRED);
        $event->setTimerFiredEventAttributes($attrs);

        return $event;
    }
}
