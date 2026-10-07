<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Store;

use Gplanchat\Bridge\Temporal\Codec\TemporalActivityScheduleInput;
use Gplanchat\Bridge\Temporal\Store\TemporalEventConverter;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Failure\ActivityRetryState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Enums\V1\TimeoutType;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\Failure\V1\TimeoutFailureInfo;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\ActivityTaskStartedEventAttributes;
use Temporal\Api\History\V1\ActivityTaskTimedOutEventAttributes;
use Temporal\Api\History\V1\HistoryEvent;

/**
 * The worker schedules an activity with an envelope (`TemporalActivityScheduleInput`), whose
 * `payload` slot holds the arguments. Read back whole, the envelope became the arguments, and the
 * journal the store returns disagreed with the one that was written (#326).
 */
final class ActivityEventConversionTest extends TestCase
{
    public function testTheScheduledActivityReadsBackAsItWasWritten(): void
    {
        $written = new ActivityScheduled(ExecutionId::fromString('exec-1'), 'act-1', 'quote', ['lines' => ['a', 'b']], ['queue' => 'default']);

        $event = new HistoryEvent();
        $event->setEventId(5);
        $event->setEventType(EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED);
        $event->setActivityTaskScheduledEventAttributes(new ActivityTaskScheduledEventAttributes([
            'activity_id' => 'act-1',
            'activity_type' => new ActivityType(['name' => 'quote']),
            'input' => TemporalActivityScheduleInput::toPayloads($written),
        ]));

        $read = (new TemporalEventConverter(ExecutionId::fromString('exec-1')))->convert($event);

        self::assertInstanceOf(ActivityScheduled::class, $read);
        self::assertSame($written->payload(), $read->payload());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function timeoutTypes(): iterable
    {
        yield 'start-to-close' => [TimeoutType::TIMEOUT_TYPE_START_TO_CLOSE, 'Activity start-to-close timeout exceeded.'];
        yield 'schedule-to-start' => [TimeoutType::TIMEOUT_TYPE_SCHEDULE_TO_START, 'Activity schedule-to-start timeout exceeded.'];
        yield 'schedule-to-close' => [TimeoutType::TIMEOUT_TYPE_SCHEDULE_TO_CLOSE, 'Activity schedule-to-close timeout exceeded.'];
        yield 'heartbeat' => [TimeoutType::TIMEOUT_TYPE_HEARTBEAT, 'Activity heartbeat timeout exceeded.'];
        yield 'unnamed' => [TimeoutType::TIMEOUT_TYPE_UNSPECIFIED, 'Activity timeout exceeded.'];
    }

    #[DataProvider('timeoutTypes')]
    public function testAnActivityTimeoutReadsAsAFailureWithTheMessageTheReaderBuilds(int $timeoutType, string $message): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));

        $scheduled = new HistoryEvent();
        $scheduled->setEventId(5);
        $scheduled->setEventType(EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED);
        $scheduled->setActivityTaskScheduledEventAttributes(new ActivityTaskScheduledEventAttributes([
            'activity_id' => 'act-1',
            'activity_type' => new ActivityType(['name' => 'quote']),
        ]));
        $converter->convert($scheduled);

        $started = new HistoryEvent();
        $started->setEventId(6);
        $started->setEventType(EventType::EVENT_TYPE_ACTIVITY_TASK_STARTED);
        $started->setActivityTaskStartedEventAttributes(new ActivityTaskStartedEventAttributes(['scheduled_event_id' => 5, 'attempt' => 3]));
        self::assertNull($converter->convert($started));

        $timedOut = new HistoryEvent();
        $timedOut->setEventId(7);
        $timedOut->setEventType(EventType::EVENT_TYPE_ACTIVITY_TASK_TIMED_OUT);
        $timedOut->setActivityTaskTimedOutEventAttributes(new ActivityTaskTimedOutEventAttributes([
            'scheduled_event_id' => 5,
            'started_event_id' => 6,
            'failure' => new Failure(['timeout_failure_info' => new TimeoutFailureInfo(['timeout_type' => $timeoutType])]),
        ]));

        $read = $converter->convert($timedOut);

        self::assertInstanceOf(ActivityFailed::class, $read);
        self::assertSame('act-1', $read->activityId());
        self::assertSame(\RuntimeException::class, $read->failureClass());
        self::assertSame($message, $read->failureMessage());
        self::assertSame(ActivityRetryState::Timeout, $read->retryState());
        self::assertSame(3, $read->failureAttempt());
        self::assertSame('quote', $read->activityName());
    }
}
