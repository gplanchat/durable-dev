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
 * A deadline settled by the other branch cancels the losing timer. Replay goes through that
 * cancellation again at every resumption, and a cancelled timer has no verdict to announce: it
 * comes back to waiting, and the cancellation is asked for again.
 *
 * The SQL journal already guarded against it — at worst a duplicate `TimerCancelled`. The bridge
 * did not, and Temporal does not forgive: the whole task is rejected
 * (`BadCancelTimerAttributes`), the worker dies, the task is redelivered, the worker dies again.
 * A single execution poisoned the whole queue.
 */
final class TimerCancelNotRepeatedTest extends TestCase
{
    private const TIMER = 'timer-1';

    public function testAnAlreadyCancelledTimerIsNotCancelledAgain(): void
    {
        $buffer = $this->bufferFor([$this->started(5), $this->cancelled(9)]);

        $buffer->cancelTimer(self::TIMER, ActivityCancellationReason::RACE_SUPERSEDED);

        self::assertSame([], $buffer->flush(), 'A second cancellation goes to the server and gets the task rejected.');
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
