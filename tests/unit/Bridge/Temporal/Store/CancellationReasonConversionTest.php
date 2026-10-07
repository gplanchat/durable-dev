<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Store;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Store\TemporalEventConverter;
use Gplanchat\Bridge\Temporal\Store\TemporalReadThroughEventStore;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\ActivityCancellationReason;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\ActivityTaskCanceledEventAttributes;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\MarkerRecordedEventAttributes;
use Temporal\Api\History\V1\TimerCanceledEventAttributes;
use Temporal\Api\History\V1\TimerStartedEventAttributes;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryResponse;

/**
 * The server records one `*_CANCELED` event whatever the workflow cancelled for. The reason comes
 * from the history, by the rule `TemporalExecutionHistory` replays with (#694): an operation the
 * delivered-cancellation marker targets was withdrawn with the workflow, any other one lost a race.
 * The read model (event store, profiler, dashboards) then agrees with the event-store backends (#701).
 */
final class CancellationReasonConversionTest extends TestCase
{
    public function testACancelledActivityWithoutTheMarkerLostARace(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));
        $converter->convert(self::activityScheduled(5, 'act-1'));

        $cancelled = $converter->convert(self::activityCanceled(9, 5));

        self::assertInstanceOf(ActivityCancelled::class, $cancelled);
        self::assertSame(ActivityCancellationReason::RACE_SUPERSEDED, $cancelled->reason());
    }

    public function testACancelledActivityTheMarkerTargetsWasCancelledWithTheWorkflow(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));
        $converter->convert(self::activityScheduled(5, 'act-1'));
        $converter->convert(self::activityScheduled(6, 'act-2'));
        $converter->convert(self::cancellationDelivered(8, ['act-1']));

        $targeted = $converter->convert(self::activityCanceled(10, 5));
        $other = $converter->convert(self::activityCanceled(11, 6));

        self::assertInstanceOf(ActivityCancelled::class, $targeted);
        self::assertSame(ActivityCancellationReason::WORKFLOW_CANCELLED, $targeted->reason());
        self::assertInstanceOf(ActivityCancelled::class, $other);
        self::assertSame(ActivityCancellationReason::RACE_SUPERSEDED, $other->reason());
    }

    public function testACancelledTimerFollowsTheSameRule(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));
        $converter->convert(self::timerStarted(5, 'timer-1'));
        $converter->convert(self::timerStarted(6, 'timer-2'));
        $converter->convert(self::cancellationDelivered(8, ['timer-1']));

        $targeted = $converter->convert(self::timerCanceled(10, 5));
        $other = $converter->convert(self::timerCanceled(11, 6));

        self::assertInstanceOf(TimerCancelled::class, $targeted);
        self::assertSame(ActivityCancellationReason::WORKFLOW_CANCELLED, $targeted->reason());
        self::assertInstanceOf(TimerCancelled::class, $other);
        self::assertSame(ActivityCancellationReason::RACE_SUPERSEDED, $other->reason());
    }

    /**
     * The order a server records: the workflow task's commands run in turn, the cancellations
     * first, so an operation that was not running is CANCELED before the marker that explains it.
     * Read through the store, the reason must not depend on that order.
     */
    public function testTheStoreReadsTheReasonWhereverTheMarkerSits(): void
    {
        $history = [
            self::activityScheduled(5, 'act-1'),
            self::activityScheduled(6, 'act-2'),
            self::timerStarted(7, 'timer-1'),
            self::activityCanceled(10, 6),
            self::activityCanceled(11, 5),
            self::timerCanceled(12, 7),
            self::cancellationDelivered(13, ['act-1', 'timer-1']),
        ];
        $client = $this->createStub(WorkflowServiceClientInterface::class);
        $client->method('GetWorkflowExecutionHistory')->willReturn(new GetWorkflowExecutionHistoryResponse(['history' => new History(['events' => $history])]));
        $workflowClient = $this->createStub(WorkflowClientInterface::class);
        $workflowClient->method('workflowId')->willReturn('durable-exec-1');
        $store = new TemporalReadThroughEventStore(new InMemoryEventStore(), new TemporalHistoryCursor($client, 'durable-test'), $workflowClient);

        $reasons = [];
        foreach ($store->readStream(ExecutionId::fromString('exec-1')) as $event) {
            if ($event instanceof ActivityCancelled) {
                $reasons[$event->activityId()] = $event->reason();
            }
            if ($event instanceof TimerCancelled) {
                $reasons[$event->timerId()] = $event->reason();
            }
        }

        self::assertSame([
            'act-2' => ActivityCancellationReason::RACE_SUPERSEDED,
            'act-1' => ActivityCancellationReason::WORKFLOW_CANCELLED,
            'timer-1' => ActivityCancellationReason::WORKFLOW_CANCELLED,
        ], $reasons);
    }

    /**
     * The caller converts the same history after the scan: a generator would be used up by then.
     * The list type refuses it up front instead of failing on the caller's second loop.
     */
    public function testTheScanTakesAListNotAGenerator(): void
    {
        $history = (static function (): \Generator {
            yield self::activityScheduled(5, 'act-1');
        })();

        $this->expectException(\TypeError::class);
        /** @psalm-suppress InvalidArgument — the wrong type is the point of the test */
        TemporalEventConverter::forHistory(ExecutionId::fromString('exec-1'), $history); // @phpstan-ignore argument.type
    }

    private static function activityScheduled(int $eventId, string $activityId): HistoryEvent
    {
        $event = self::event($eventId, EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED);
        $event->setActivityTaskScheduledEventAttributes(new ActivityTaskScheduledEventAttributes([
            'activity_id' => $activityId,
            'activity_type' => new ActivityType(['name' => 'quote']),
        ]));

        return $event;
    }

    private static function activityCanceled(int $eventId, int $scheduledEventId): HistoryEvent
    {
        $event = self::event($eventId, EventType::EVENT_TYPE_ACTIVITY_TASK_CANCELED);
        $event->setActivityTaskCanceledEventAttributes(new ActivityTaskCanceledEventAttributes(['scheduled_event_id' => $scheduledEventId]));

        return $event;
    }

    private static function timerStarted(int $eventId, string $timerId): HistoryEvent
    {
        $event = self::event($eventId, EventType::EVENT_TYPE_TIMER_STARTED);
        $event->setTimerStartedEventAttributes(new TimerStartedEventAttributes(['timer_id' => $timerId]));

        return $event;
    }

    private static function timerCanceled(int $eventId, int $startedEventId): HistoryEvent
    {
        $event = self::event($eventId, EventType::EVENT_TYPE_TIMER_CANCELED);
        $event->setTimerCanceledEventAttributes(new TimerCanceledEventAttributes(['started_event_id' => $startedEventId]));

        return $event;
    }

    /**
     * @param list<string> $targets
     */
    private static function cancellationDelivered(int $eventId, array $targets): HistoryEvent
    {
        $event = self::event($eventId, EventType::EVENT_TYPE_MARKER_RECORDED);
        $event->setMarkerRecordedEventAttributes(new MarkerRecordedEventAttributes([
            'marker_name' => TemporalExecutionHistory::MARKER_CANCELLATION_DELIVERED,
            'details' => ['targets' => JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($targets))],
        ]));

        return $event;
    }

    private static function event(int $eventId, int $type): HistoryEvent
    {
        $event = new HistoryEvent();
        $event->setEventId($eventId);
        $event->setEventType($type);

        return $event;
    }
}
