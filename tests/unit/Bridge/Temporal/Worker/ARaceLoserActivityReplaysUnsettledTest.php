<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\ActivityCancellationReason;
use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\ActivityTaskCanceledEventAttributes;
use Temporal\Api\History\V1\ActivityTaskCancelRequestedEventAttributes;
use Temporal\Api\History\V1\ActivityTaskCompletedEventAttributes;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\HistoryEvent;

/**
 * An activity the workflow cancelled as the loser of `any()` replays as unsettled, as a losing
 * timer does, and its cancellation is not requested again (#681, the Temporal side of #678).
 *
 * Read back as a rejection, it settled first on replay and won the race it had lost. Requested
 * again, the server rejects the task on an activity whose cancellation it already recorded.
 */
final class ARaceLoserActivityReplaysUnsettledTest extends TestCase
{
    private const SCHEDULED = 5;

    public function testACancelledActivityReplaysUnsettled(): void
    {
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(), $this->cancelRequested(9), $this->canceled(10)]);

        self::assertNull($history->findActivitySlotResult(0));
    }

    public function testWhatTheLoserRecordsAfterItsCancellationIsNotRead(): void
    {
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(), $this->cancelRequested(9), $this->completed(12)]);

        self::assertNull($history->findActivitySlotResult(0), 'a late result would re-decide the race');
    }

    public function testTheCancellationIsNotRequestedAgain(): void
    {
        $buffer = $this->bufferFor([$this->scheduled(), $this->cancelRequested(9), $this->canceled(10)]);

        $buffer->cancelActivity('act-1', ActivityCancellationReason::RACE_SUPERSEDED);

        self::assertSame([], $buffer->flush());
    }

    public function testAPendingActivityIsCancelledOnce(): void
    {
        $buffer = $this->bufferFor([$this->scheduled()]);

        $buffer->cancelActivity('act-1', ActivityCancellationReason::RACE_SUPERSEDED);

        $commands = $buffer->flush();
        self::assertCount(1, $commands);
        self::assertSame(CommandType::COMMAND_TYPE_REQUEST_CANCEL_ACTIVITY_TASK, $commands[0]->getCommandType());
        self::assertSame(self::SCHEDULED, (int) $commands[0]->getRequestCancelActivityTaskCommandAttributes()?->getScheduledEventId());
    }

    /**
     * @param list<HistoryEvent> $events
     */
    private function bufferFor(array $events): TemporalWorkflowCommandBuffer
    {
        return new TemporalWorkflowCommandBuffer(
            TemporalConnection::fromDsn('temporal://127.0.0.1:7233?namespace=default&tls=0'),
            ExecutionId::fromString('exec-1'),
            TemporalExecutionHistory::fromEvents($events),
        );
    }

    private function scheduled(): HistoryEvent
    {
        return new HistoryEvent([
            'event_id' => self::SCHEDULED,
            'event_type' => EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED,
            'activity_task_scheduled_event_attributes' => new ActivityTaskScheduledEventAttributes([
                'activity_id' => 'act-1',
                'activity_type' => new ActivityType(['name' => 'boom']),
            ]),
        ]);
    }

    private function cancelRequested(int $eventId): HistoryEvent
    {
        return new HistoryEvent([
            'event_id' => $eventId,
            'event_type' => EventType::EVENT_TYPE_ACTIVITY_TASK_CANCEL_REQUESTED,
            'activity_task_cancel_requested_event_attributes' => new ActivityTaskCancelRequestedEventAttributes(['scheduled_event_id' => self::SCHEDULED]),
        ]);
    }

    private function canceled(int $eventId): HistoryEvent
    {
        return new HistoryEvent([
            'event_id' => $eventId,
            'event_type' => EventType::EVENT_TYPE_ACTIVITY_TASK_CANCELED,
            'activity_task_canceled_event_attributes' => new ActivityTaskCanceledEventAttributes(['scheduled_event_id' => self::SCHEDULED]),
        ]);
    }

    private function completed(int $eventId): HistoryEvent
    {
        return new HistoryEvent([
            'event_id' => $eventId,
            'event_type' => EventType::EVENT_TYPE_ACTIVITY_TASK_COMPLETED,
            'activity_task_completed_event_attributes' => new ActivityTaskCompletedEventAttributes([
                'scheduled_event_id' => self::SCHEDULED,
                'result' => JsonPlainPayload::singlePayloads(JsonPlainPayload::encode('late')),
            ]),
        ]);
    }
}
