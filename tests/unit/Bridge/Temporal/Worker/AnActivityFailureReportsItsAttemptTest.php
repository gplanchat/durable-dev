<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Failure\ActivityFailureEventFactory;
use Gplanchat\Durable\Failure\ActivityRetryState;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Enums\V1\TimeoutType;
use Temporal\Api\Failure\V1\ApplicationFailureInfo;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\Failure\V1\TimeoutFailureInfo;
use Temporal\Api\History\V1\ActivityTaskFailedEventAttributes;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\ActivityTaskStartedEventAttributes;
use Temporal\Api\History\V1\ActivityTaskTimedOutEventAttributes;
use Temporal\Api\History\V1\HistoryEvent;

/**
 * A workflow reading `$e->attempt()` gets the attempt that failed, on Temporal as on the journal
 * backends. Temporal writes only the last attempt's ActivityTaskStarted, and the failure points at
 * it (#547).
 *
 * @internal
 */
final class AnActivityFailureReportsItsAttemptTest extends TestCase
{
    public function testAFailureOnTheThirdAttemptReportsTheAttemptTheJournalReports(): void
    {
        $failed = new HistoryEvent(['event_id' => 4, 'event_type' => EventType::EVENT_TYPE_ACTIVITY_TASK_FAILED]);
        $failed->setActivityTaskFailedEventAttributes(new ActivityTaskFailedEventAttributes([
            'scheduled_event_id' => 2,
            'started_event_id' => 3,
            'failure' => new Failure(['message' => 'card declined', 'application_failure_info' => new ApplicationFailureInfo(['type' => \RuntimeException::class])]),
        ]));

        $onTemporal = self::failure(3, $failed);
        $journal = ActivityFailureEventFactory::fromActivityThrowable('exec-1', 'act-1', 'Charge', 3, new \RuntimeException('card declined'), ActivityRetryState::MaximumAttemptsReached);
        self::assertInstanceOf(ActivityFailed::class, $journal);
        $onTheJournal = DurableActivityFailedException::toThrowable($journal);
        self::assertInstanceOf(DurableActivityFailedException::class, $onTheJournal);

        self::assertSame(3, $onTemporal->attempt());
        self::assertSame($onTheJournal->attempt(), $onTemporal->attempt());
        self::assertSame($onTheJournal->getMessage(), $onTemporal->getMessage(), 'the attempt is part of the message a workflow logs');
    }

    public function testATimeoutAfterItsSecondAttemptReportsTheSecondAttempt(): void
    {
        self::assertSame(2, self::failure(2, self::timedOut(TimeoutType::TIMEOUT_TYPE_START_TO_CLOSE, 3))->attempt());
    }

    public function testATimeoutBeforeAnyStartReportsTheFirstAttemptAsTheJournalDoes(): void
    {
        // Schedule-to-start: no attempt ever started, and the journal reports the first one.
        self::assertSame(1, self::failure(null, self::timedOut(TimeoutType::TIMEOUT_TYPE_SCHEDULE_TO_START, 0))->attempt());
    }

    private static function timedOut(int $timeoutType, int $startedEventId): HistoryEvent
    {
        $event = new HistoryEvent(['event_id' => 4, 'event_type' => EventType::EVENT_TYPE_ACTIVITY_TASK_TIMED_OUT]);
        $event->setActivityTaskTimedOutEventAttributes(new ActivityTaskTimedOutEventAttributes([
            'scheduled_event_id' => 2,
            'started_event_id' => $startedEventId,
            'failure' => new Failure(['timeout_failure_info' => new TimeoutFailureInfo(['timeout_type' => $timeoutType])]),
        ]));

        return $event;
    }

    /**
     * @param int|null $startedAttempt the attempt of the last ActivityTaskStarted, none when null
     */
    private static function failure(?int $startedAttempt, HistoryEvent $ending): DurableActivityFailedException
    {
        $scheduled = new HistoryEvent(['event_id' => 2, 'event_type' => EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED]);
        $scheduled->setActivityTaskScheduledEventAttributes(new ActivityTaskScheduledEventAttributes([
            'activity_id' => 'act-1',
            'activity_type' => new ActivityType(['name' => 'Charge']),
        ]));
        $events = [new HistoryEvent(['event_id' => 1, 'event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED]), $scheduled];
        if (null !== $startedAttempt) {
            $started = new HistoryEvent(['event_id' => 3, 'event_type' => EventType::EVENT_TYPE_ACTIVITY_TASK_STARTED]);
            $started->setActivityTaskStartedEventAttributes(new ActivityTaskStartedEventAttributes(['scheduled_event_id' => 2, 'attempt' => $startedAttempt]));
            $events[] = $started;
        }
        $events[] = $ending;

        $failed = TemporalExecutionHistory::fromEvents($events)->findActivitySlotResult(0)['failed'] ?? null;
        self::assertInstanceOf(DurableActivityFailedException::class, $failed);

        return $failed;
    }
}
