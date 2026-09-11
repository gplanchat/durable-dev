<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Google\Protobuf\Duration;
use Google\Protobuf\Timestamp;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\ChildWorkflowExecutionCompletedEventAttributes;
use Temporal\Api\History\V1\ChildWorkflowExecutionStartedEventAttributes;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\StartChildWorkflowExecutionInitiatedEventAttributes;
use Temporal\Api\History\V1\TimerStartedEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionSignaledEventAttributes;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryResponse;

/**
 * The Temporal history, read behind the port.
 *
 * Two filing traps the moved code had, and that must not be carried over: the event type of a
 * signal (`WORKFLOW_EXECUTION_SIGNALED`) contains `WORKFLOW_`, and so does that of a child workflow
 * (`START_CHILD_WORKFLOW_EXECUTION_INITIATED`). Testing `WORKFLOW_` first therefore files signals
 * and children on the execution lane — which is what the plugin's provider does today.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/tasks.md §5.1
 */
final class TemporalWorkflowRunHistoryTest extends TestCase
{
    public function testActivitiesAreLabelledWithTheirTypeName(): void
    {
        $history = $this->readHistory(
            $this->activityScheduled(1, 'SendWelcomeEmail'),
        );

        self::assertSame(WorkflowRunEventKind::Activity, $history[0]->kind);
        self::assertSame('SendWelcomeEmail', $history[0]->label);
    }

    public function testASignalDoesNotLandOnTheExecutionLane(): void
    {
        $history = $this->readHistory($this->signalled(2, 'orderApproved'));

        self::assertSame(WorkflowRunEventKind::Signal, $history[0]->kind);
        self::assertSame('orderApproved', $history[0]->label);
    }

    public function testAChildWorkflowDoesNotLandOnTheExecutionLane(): void
    {
        $history = $this->readHistory(
            $this->event(3, EventType::EVENT_TYPE_START_CHILD_WORKFLOW_EXECUTION_INITIATED),
        );

        self::assertSame(WorkflowRunEventKind::Other, $history[0]->kind);
    }

    public function testTheExecutionLaneKeepsItsOwnEvents(): void
    {
        $history = $this->readHistory(
            $this->event(1, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED),
            $this->event(9, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED),
        );

        self::assertSame(WorkflowRunEventKind::Execution, $history[0]->kind);
        self::assertSame(WorkflowRunEventKind::Execution, $history[1]->kind);
    }

    public function testEventsKeepTheirServerSequenceAndTime(): void
    {
        $history = $this->readHistory(
            $this->event(1, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED, 1_700_000_000),
            $this->event(7, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED, 1_700_000_060),
        );

        self::assertSame([1, 7], array_map(static fn($e): int => $e->sequence, $history));
        self::assertSame(1_700_000_000, $history[0]->recordedAt->getTimestamp());
        self::assertSame(1_700_000_060, $history[1]->recordedAt->getTimestamp());
    }

    public function testARunWithoutItsGroupingIdentifierHasNoReadableHistory(): void
    {
        // Temporal requires the workflow id to find a history back: without it there is nothing
        // to ask the server for, and inventing an empty call would be worse than saying so.
        $catalog = new TemporalWorkflowRunCatalog(
            $this->client($this->historyResponse()),
            $this->connection(),
            new TemporalHistoryCursor($this->client($this->historyResponse()), $this->connection()),
        );

        self::assertSame([], $catalog->readHistory($this->describedRun(groupId: null)));
    }

    /**
     * @return list<\Gplanchat\Durable\Observation\WorkflowRunEvent>
     */
    public function testAChildWorkflowIsOneActionAndNotTwo(): void
    {
        // The ending of a child execution carries `initiatedEventId` **and** `startedEventId`.
        // Looked up in the wrong order, `startedEventId` names the start of the child and not the
        // event that founded it: the child then took two frieze lines, neither of which said its
        // duration. Measured on the all-cases probe before being fixed here.
        $history = $this->readHistory(
            $this->childInitiated(1, 'App\\ShipmentWorkflow'),
            $this->childStarted(2, initiatedEventId: 1),
            $this->childCompleted(3, initiatedEventId: 1, startedEventId: 2),
        );

        self::assertSame($history[0]->actionKey, $history[1]->actionKey);
        self::assertSame($history[0]->actionKey, $history[2]->actionKey, 'the ending of the child joins its request');
    }

    public function testATimerIsNamedByItsDelay(): void
    {
        // "TIMER STARTED" names the class of event. A timer has no business name: its delay is
        // the only fact it carries, so that is its name.
        $history = $this->readHistory($this->timerStarted(1, 5));

        self::assertSame('timer 5.0 s', $history[0]->label);
    }

    private function childInitiated(int $eventId, string $workflowType): HistoryEvent
    {
        $type = new WorkflowType();
        $type->setName($workflowType);

        $attributes = new StartChildWorkflowExecutionInitiatedEventAttributes();
        $attributes->setWorkflowType($type);

        $event = $this->event($eventId, EventType::EVENT_TYPE_START_CHILD_WORKFLOW_EXECUTION_INITIATED);
        $event->setStartChildWorkflowExecutionInitiatedEventAttributes($attributes);

        return $event;
    }

    private function childStarted(int $eventId, int $initiatedEventId): HistoryEvent
    {
        $attributes = new ChildWorkflowExecutionStartedEventAttributes();
        $attributes->setInitiatedEventId($initiatedEventId);

        $event = $this->event($eventId, EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_STARTED);
        $event->setChildWorkflowExecutionStartedEventAttributes($attributes);

        return $event;
    }

    private function childCompleted(int $eventId, int $initiatedEventId, int $startedEventId): HistoryEvent
    {
        $attributes = new ChildWorkflowExecutionCompletedEventAttributes();
        $attributes->setInitiatedEventId($initiatedEventId);
        $attributes->setStartedEventId($startedEventId);

        $event = $this->event($eventId, EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_COMPLETED);
        $event->setChildWorkflowExecutionCompletedEventAttributes($attributes);

        return $event;
    }

    private function timerStarted(int $eventId, int $seconds): HistoryEvent
    {
        $delay = new Duration();
        $delay->setSeconds($seconds);

        $attributes = new TimerStartedEventAttributes();
        $attributes->setTimerId('tim-1');
        $attributes->setStartToFireTimeout($delay);

        $event = $this->event($eventId, EventType::EVENT_TYPE_TIMER_STARTED);
        $event->setTimerStartedEventAttributes($attributes);

        return $event;
    }

    private function readHistory(HistoryEvent ...$events): array
    {
        $client = $this->client($this->historyResponse(...$events));
        $catalog = new TemporalWorkflowRunCatalog(
            $client,
            $this->connection(),
            new TemporalHistoryCursor($client, $this->connection()),
        );

        return $catalog->readHistory($this->describedRun());
    }

    private function describedRun(?string $groupId = 'wf-1'): WorkflowRunDescription
    {
        return new WorkflowRunDescription('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running, null, null, $groupId);
    }

    private function event(int $eventId, int $type, int $seconds = 1_700_000_000): HistoryEvent
    {
        $time = new Timestamp();
        $time->setSeconds($seconds);

        $event = new HistoryEvent();
        $event->setEventId($eventId);
        $event->setEventType($type);
        $event->setEventTime($time);

        return $event;
    }

    private function activityScheduled(int $eventId, string $name): HistoryEvent
    {
        $type = new ActivityType();
        $type->setName($name);

        $attributes = new ActivityTaskScheduledEventAttributes();
        $attributes->setActivityId('act-1');
        $attributes->setActivityType($type);

        $event = $this->event($eventId, EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED);
        $event->setActivityTaskScheduledEventAttributes($attributes);

        return $event;
    }

    private function signalled(int $eventId, string $signalName): HistoryEvent
    {
        $attributes = new WorkflowExecutionSignaledEventAttributes();
        $attributes->setSignalName($signalName);

        $event = $this->event($eventId, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED);
        $event->setWorkflowExecutionSignaledEventAttributes($attributes);

        return $event;
    }

    private function historyResponse(HistoryEvent ...$events): GetWorkflowExecutionHistoryResponse
    {
        $history = new History();
        $history->setEvents($events);

        $response = new GetWorkflowExecutionHistoryResponse();
        $response->setHistory($history);
        $response->setNextPageToken('');

        return $response;
    }

    private function connection(): TemporalConnection
    {
        return new TemporalConnection('localhost:7233', 'durable-test');
    }

    private function client(GetWorkflowExecutionHistoryResponse $response): WorkflowServiceClientInterface
    {
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->method('GetWorkflowExecutionHistory')->willReturn($response);

        return $client;
    }
}
