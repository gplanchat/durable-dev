<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Failure\ActivityFailureEventFactory;
use Gplanchat\Durable\Failure\ActivityRetryState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Enums\V1\TimeoutType;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\Failure\V1\TimeoutFailureInfo;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\ActivityTaskTimedOutEventAttributes;
use Temporal\Api\History\V1\HistoryEvent;

/**
 * An activity whose last attempt timed out is a failed activity, on Temporal as on the journal
 * backends: without it, the workflow found neither a result nor a failure and waited forever (#544).
 */
final class AnActivityTimeoutFailsItsSlotTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function timeouts(): iterable
    {
        yield 'start-to-close' => [TimeoutType::TIMEOUT_TYPE_START_TO_CLOSE, 'Activity start-to-close timeout exceeded.'];
        yield 'schedule-to-start' => [TimeoutType::TIMEOUT_TYPE_SCHEDULE_TO_START, 'Activity schedule-to-start timeout exceeded.'];
        yield 'schedule-to-close' => [TimeoutType::TIMEOUT_TYPE_SCHEDULE_TO_CLOSE, 'Activity schedule-to-close timeout exceeded.'];
        yield 'heartbeat' => [TimeoutType::TIMEOUT_TYPE_HEARTBEAT, 'Activity heartbeat timeout exceeded.'];
    }

    #[DataProvider('timeouts')]
    public function testATimedOutActivityFailsItsSlot(int $timeoutType, string $message): void
    {
        $slot = self::historyTimingOut($timeoutType)->findActivitySlotResult(0);

        self::assertNotNull($slot, 'the slot is settled, not left waiting');
        self::assertNull($slot['result']);
        self::assertInstanceOf(DurableActivityFailedException::class, $slot['failed']);
        self::assertSame(\RuntimeException::class, $slot['failed']->envelope()->class);
        self::assertSame($message, $slot['failed']->envelope()->message);
        self::assertSame('SlowOne', $slot['failed']->activityName());
    }

    /**
     * The journal backends raise their own start-to-close timeout the same way: a workflow catches
     * the same exception, with the same envelope, whichever backend it runs on.
     */
    public function testTheFailureIsTheOneTheJournalBackendsRaise(): void
    {
        $journal = ActivityFailureEventFactory::fromActivityThrowable('exec-1', 'act-1', 'SlowOne', 1, new \RuntimeException('Activity start-to-close timeout exceeded.'), ActivityRetryState::Timeout);
        self::assertInstanceOf(ActivityFailed::class, $journal);
        $onTheJournal = DurableActivityFailedException::toThrowable($journal);

        $onTemporal = self::historyTimingOut(TimeoutType::TIMEOUT_TYPE_START_TO_CLOSE)->findActivitySlotResult(0)['failed'] ?? null;

        self::assertInstanceOf(DurableActivityFailedException::class, $onTheJournal);
        self::assertInstanceOf(DurableActivityFailedException::class, $onTemporal);
        self::assertSame($onTheJournal::class, $onTemporal::class);
        self::assertSame($onTheJournal->envelope()->class, $onTemporal->envelope()->class);
        self::assertSame($onTheJournal->envelope()->message, $onTemporal->envelope()->message);
    }

    private static function historyTimingOut(int $timeoutType): TemporalExecutionHistory
    {
        $scheduled = new HistoryEvent(['event_id' => 2, 'event_type' => EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED]);
        $scheduled->setActivityTaskScheduledEventAttributes(new ActivityTaskScheduledEventAttributes([
            'activity_id' => 'act-1',
            'activity_type' => new ActivityType(['name' => 'SlowOne']),
        ]));

        $timedOut = new HistoryEvent(['event_id' => 3, 'event_type' => EventType::EVENT_TYPE_ACTIVITY_TASK_TIMED_OUT]);
        $timedOut->setActivityTaskTimedOutEventAttributes(new ActivityTaskTimedOutEventAttributes([
            'scheduled_event_id' => 2,
            'failure' => new Failure([
                'message' => 'activity timeout',
                'timeout_failure_info' => new TimeoutFailureInfo(['timeout_type' => $timeoutType]),
            ]),
        ]));

        return TemporalExecutionHistory::fromEvents([
            new HistoryEvent(['event_id' => 1, 'event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED]),
            $scheduled,
            $timedOut,
        ]);
    }
}
