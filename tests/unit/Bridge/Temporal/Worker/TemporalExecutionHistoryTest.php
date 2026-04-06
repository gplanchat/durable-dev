<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\History\V1\ActivityTaskCompletedEventAttributes;
use Temporal\Api\History\V1\ActivityTaskFailedEventAttributes;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\TimerFiredEventAttributes;
use Temporal\Api\History\V1\TimerStartedEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionSignaledEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionStartedEventAttributes;

/**
 * Tests for TemporalExecutionHistory — parsing Temporal history events and slot lookups.
 *
 * These are pure unit tests: no gRPC, no Fiber, no registry — just event-to-history mapping.
 */
final class TemporalExecutionHistoryTest extends TestCase
{
    private static function makeEvent(int $id, int $eventType): HistoryEvent
    {
        $event = new HistoryEvent();
        $event->setEventId($id);
        $event->setEventType($eventType);

        return $event;
    }

    private static function makeStartedEvent(int $id, array $input = []): HistoryEvent
    {
        $event = self::makeEvent($id, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED);
        $attr = new WorkflowExecutionStartedEventAttributes();
        $ps = new Payloads();
        $ps->setPayloads([JsonPlainPayload::encode($input)]);
        $attr->setInput($ps);
        $event->setWorkflowExecutionStartedEventAttributes($attr);

        return $event;
    }

    private static function makeActivityScheduled(int $id, string $activityId): HistoryEvent
    {
        $event = self::makeEvent($id, EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED);
        $attr = new ActivityTaskScheduledEventAttributes();
        $attr->setActivityId($activityId);
        $event->setActivityTaskScheduledEventAttributes($attr);

        return $event;
    }

    private static function makeActivityCompleted(int $id, int $scheduledEventId, mixed $result): HistoryEvent
    {
        $event = self::makeEvent($id, EventType::EVENT_TYPE_ACTIVITY_TASK_COMPLETED);
        $attr = new ActivityTaskCompletedEventAttributes();
        $attr->setScheduledEventId($scheduledEventId);
        $ps = new Payloads();
        $ps->setPayloads([JsonPlainPayload::encode($result)]);
        $attr->setResult($ps);
        $event->setActivityTaskCompletedEventAttributes($attr);

        return $event;
    }

    private static function makeActivityFailed(int $id, int $scheduledEventId, string $message): HistoryEvent
    {
        $event = self::makeEvent($id, EventType::EVENT_TYPE_ACTIVITY_TASK_FAILED);
        $attr = new ActivityTaskFailedEventAttributes();
        $attr->setScheduledEventId($scheduledEventId);
        $failure = new Failure();
        $failure->setMessage($message);
        $attr->setFailure($failure);
        $event->setActivityTaskFailedEventAttributes($attr);

        return $event;
    }

    private static function makeTimerStarted(int $id, string $timerId): HistoryEvent
    {
        $event = self::makeEvent($id, EventType::EVENT_TYPE_TIMER_STARTED);
        $attr = new TimerStartedEventAttributes();
        $attr->setTimerId($timerId);
        $event->setTimerStartedEventAttributes($attr);

        return $event;
    }

    private static function makeTimerFired(int $id, int $startedEventId): HistoryEvent
    {
        $event = self::makeEvent($id, EventType::EVENT_TYPE_TIMER_FIRED);
        $attr = new TimerFiredEventAttributes();
        $attr->setStartedEventId($startedEventId);
        $event->setTimerFiredEventAttributes($attr);

        return $event;
    }

    private static function makeSignal(int $id, string $signalName, mixed $payload): HistoryEvent
    {
        $event = self::makeEvent($id, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED);
        $attr = new WorkflowExecutionSignaledEventAttributes();
        $attr->setSignalName($signalName);
        $ps = new Payloads();
        $ps->setPayloads([JsonPlainPayload::encode($payload)]);
        $attr->setInput($ps);
        $event->setWorkflowExecutionSignaledEventAttributes($attr);

        return $event;
    }

    public function testEmptyHistoryReturnsNoSlots(): void
    {
        $history = TemporalExecutionHistory::fromEvents([]);

        self::assertNull($history->findActivitySlotResult(0));
        self::assertNull($history->findTimerSlotResult(0));
        self::assertNull($history->startInput()['name'] ?? null);
    }

    public function testStartInputIsDecodedFromStartedEvent(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            self::makeStartedEvent(1, ['name' => 'Alice', 'amount' => 42]),
        ]);

        self::assertSame(['name' => 'Alice', 'amount' => 42], $history->startInput());
    }

    public function testActivitySlotResultNullWhenScheduledButNotCompleted(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            self::makeStartedEvent(1),
            self::makeActivityScheduled(2, 'act-1'),
        ]);

        self::assertNull($history->findActivitySlotResult(0), 'Slot 0 should be unsettled (activity not completed)');
        self::assertNull($history->findActivitySlotResult(1), 'Slot 1 should not exist');
    }

    public function testActivitySlotResultReturnedWhenCompleted(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            self::makeStartedEvent(1),
            self::makeActivityScheduled(2, 'act-1'),
            self::makeActivityCompleted(3, 2, 'hello'),
        ]);

        $slot = $history->findActivitySlotResult(0);
        self::assertNotNull($slot);
        self::assertSame('hello', $slot['result']);
        self::assertNull($slot['failed']);
    }

    public function testActivitySlotResultReturnedWhenFailed(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            self::makeStartedEvent(1),
            self::makeActivityScheduled(2, 'act-2'),
            self::makeActivityFailed(3, 2, 'Something went wrong'),
        ]);

        $slot = $history->findActivitySlotResult(0);
        self::assertNotNull($slot);
        self::assertNull($slot['result']);
        self::assertInstanceOf(\Throwable::class, $slot['failed']);
        self::assertStringContainsString('Something went wrong', $slot['failed']->getMessage());
    }

    public function testParallelActivitiesSlotsAreOrderedBySchedule(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            self::makeStartedEvent(1),
            self::makeActivityScheduled(2, 'act-A'),
            self::makeActivityScheduled(3, 'act-B'),
            self::makeActivityCompleted(4, 2, 'result-A'),
            self::makeActivityCompleted(5, 3, 'result-B'),
        ]);

        $slot0 = $history->findActivitySlotResult(0);
        $slot1 = $history->findActivitySlotResult(1);

        self::assertNotNull($slot0);
        self::assertSame('result-A', $slot0['result']);
        self::assertNotNull($slot1);
        self::assertSame('result-B', $slot1['result']);
    }

    public function testTimerSlotNullWhenStartedButNotFired(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            self::makeStartedEvent(1),
            self::makeTimerStarted(2, 'timer-1'),
        ]);

        self::assertNull($history->findTimerSlotResult(0), 'Timer not yet fired → unsettled');
    }

    public function testTimerSlotResultReturnedWhenFired(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            self::makeStartedEvent(1),
            self::makeTimerStarted(2, 'timer-1'),
            self::makeTimerFired(3, 2),
        ]);

        $slot = $history->findTimerSlotResult(0);
        self::assertNotNull($slot);
        self::assertSame('timer-1', $slot['id']);
    }

    public function testSignalFoundByNameAndSlot(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            self::makeStartedEvent(1),
            self::makeSignal(2, 'approve', ['approved' => true]),
            self::makeSignal(3, 'reject', ['reason' => 'no budget']),
            self::makeSignal(4, 'approve', ['approved' => true, 'second' => true]),
        ]);

        $approveSlot0 = $history->findSignalForSlot('approve', 0);
        self::assertNotNull($approveSlot0);
        self::assertSame(['approved' => true], $approveSlot0['payload']);

        $rejectSlot0 = $history->findSignalForSlot('reject', 0);
        self::assertNotNull($rejectSlot0);
        self::assertSame(['reason' => 'no budget'], $rejectSlot0['payload']);

        $approveSlot1 = $history->findSignalForSlot('approve', 1);
        self::assertNotNull($approveSlot1);
        self::assertTrue($approveSlot1['payload']['second'] ?? false);

        self::assertNull($history->findSignalForSlot('approve', 2), 'Third approve does not exist');
        self::assertNull($history->findSignalForSlot('unknown', 0), 'Unknown signal name');
    }

    public function testFindScheduledActivityId(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            self::makeStartedEvent(1),
            self::makeActivityScheduled(2, 'my-activity-id'),
        ]);

        self::assertSame('my-activity-id', $history->findScheduledActivityId(0));
        self::assertNull($history->findScheduledActivityId(1));
    }
}
