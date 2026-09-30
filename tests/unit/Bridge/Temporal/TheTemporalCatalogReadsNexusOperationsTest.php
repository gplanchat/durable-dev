<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Google\Protobuf\Timestamp;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Observation\NexusOperationState;
use Gplanchat\Durable\Observation\NexusOperationSummary;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\NexusOperationCatalogInterface;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\NexusOperationCompletedEventAttributes;
use Temporal\Api\History\V1\NexusOperationScheduledEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionStartedEventAttributes;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryResponse;

/**
 * The Temporal catalog says where a run's Nexus operations are served and whether they are settled
 * (#671): a dashboard reads the catalog, and Nexus exists on Temporal only (DUR036).
 */
final class TheTemporalCatalogReadsNexusOperationsTest extends TestCase
{
    public function testEachOperationIsReadFromTheServersHistory(): void
    {
        $catalog = $this->catalog(
            $this->scheduled(5, 'demo-business-billing', 'billing', 'verify'),
            $this->completed(6, 5),
            $this->scheduled(9, 'demo-business-billing', 'billing', 'charge'),
        );

        self::assertInstanceOf(NexusOperationCatalogInterface::class, $catalog);
        self::assertEquals([
            new NexusOperationSummary('demo-business-billing', 'billing', 'verify', NexusOperationState::Completed),
            new NexusOperationSummary('demo-business-billing', 'billing', 'charge', NexusOperationState::InFlight),
        ], $catalog->readNexusOperations($this->describedRun('wf-1')));
    }

    public function testAPayloadInAnotherEncodingDoesNotBreakTheReading(): void
    {
        // Another SDK's worker or a payload codec: readHistory() already tolerates it, and the run
        // page must not answer 500 because the Nexus operations were read from the same history.
        $started = $this->event(1, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED);
        $started->setWorkflowExecutionStartedEventAttributes(new WorkflowExecutionStartedEventAttributes([
            'input' => new Payloads(['payloads' => [new Payload(['metadata' => ['encoding' => 'binary/protobuf'], 'data' => "\x00\x01\x02"])]]),
        ]));

        self::assertEquals(
            [new NexusOperationSummary('demo-shop-stock', 'stock', 'reserve', NexusOperationState::InFlight)],
            $this->catalog($started, $this->scheduled(5, 'demo-shop-stock', 'stock', 'reserve'))->readNexusOperations($this->describedRun('wf-1')),
        );
    }

    public function testARunWithoutItsGroupingIdentifierHasNoOperationsToRead(): void
    {
        self::assertSame([], $this->catalog($this->scheduled(5, 'e', 's', 'o'))->readNexusOperations($this->describedRun(null)));
    }

    private function catalog(HistoryEvent ...$events): TemporalWorkflowRunCatalog
    {
        $history = new History();
        $history->setEvents($events);
        $response = new GetWorkflowExecutionHistoryResponse();
        $response->setHistory($history);
        $response->setNextPageToken('');

        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->method('GetWorkflowExecutionHistory')->willReturn($response);
        $connection = new TemporalConnection('localhost:7233', 'durable-test');

        return new TemporalWorkflowRunCatalog($client, $connection, new TemporalHistoryCursor($client, $connection));
    }

    private function describedRun(?string $groupId): WorkflowRunDescription
    {
        return new WorkflowRunDescription('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running, null, null, $groupId);
    }

    private function scheduled(int $eventId, string $endpoint, string $service, string $operation): HistoryEvent
    {
        $event = $this->event($eventId, EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED);
        $event->setNexusOperationScheduledEventAttributes(new NexusOperationScheduledEventAttributes(['endpoint' => $endpoint, 'service' => $service, 'operation' => $operation]));

        return $event;
    }

    private function completed(int $eventId, int $scheduledEventId): HistoryEvent
    {
        $event = $this->event($eventId, EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED);
        $event->setNexusOperationCompletedEventAttributes(new NexusOperationCompletedEventAttributes(['scheduled_event_id' => $scheduledEventId]));

        return $event;
    }

    private function event(int $eventId, int $type): HistoryEvent
    {
        $event = new HistoryEvent();
        $event->setEventId($eventId);
        $event->setEventType($type);
        $event->setEventTime(new Timestamp(['seconds' => 1_700_000_000]));

        return $event;
    }
}
