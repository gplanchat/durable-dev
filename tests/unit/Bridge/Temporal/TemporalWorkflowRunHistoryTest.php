<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Google\Protobuf\Timestamp;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Grpc\UnaryCall;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionSignaledEventAttributes;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * L'historique Temporal, lu derrière le port.
 *
 * Deux pièges de rangement que le code déplacé avait, et qu'il ne faut pas emporter : le type
 * d'événement d'un signal (`WORKFLOW_EXECUTION_SIGNALED`) contient `WORKFLOW_`, et celui d'un
 * workflow enfant (`START_CHILD_WORKFLOW_EXECUTION_INITIATED`) aussi. Tester `WORKFLOW_` en premier
 * range donc les signaux et les enfants sur la voie de l'exécution — ce que le fournisseur du
 * plugin fait aujourd'hui.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/tasks.md §5.1
 */
#[RequiresPhpExtension('grpc')]
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
        // Temporal exige le workflow id pour retrouver une histoire : sans lui, il n'y a rien à
        // demander au serveur, et inventer un appel vide serait pire que de le dire.
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

    private function client(GetWorkflowExecutionHistoryResponse $response): WorkflowServiceClient
    {
        $status = new \stdClass();
        $status->code = \Grpc\STATUS_OK;
        $status->details = '';

        $call = $this->createMock(UnaryCall::class);
        $call->method('wait')->willReturn([$response, $status]);

        $client = $this->createMock(WorkflowServiceClient::class);
        $client->method('GetWorkflowExecutionHistory')->willReturn($call);

        return $client;
    }
}
