<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Codec\TemporalActivityScheduleInput;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\NexusOperationScheduledEventAttributes;
use Temporal\Api\History\V1\StartChildWorkflowExecutionInitiatedEventAttributes;

/**
 * The payload guard (DUR042) reads back three wire shapes, and **they are not interchangeable**.
 *
 * This is where an unwrapping error would hide: all three look like "the call's input", and each
 * is written differently by the buffer.
 *
 * - activity — single-element `Payloads`, carrying the envelope
 *   {@see TemporalActivityScheduleInput} `{executionId, activityId, activityName, payload, metadata}`;
 * - Nexus — a **naked** `Payload`, the caller's payload without an envelope;
 * - child — single-element `Payloads`, carrying the **naked** input.
 *
 * The fixtures are written from what `TemporalWorkflowCommandBuffer` really produces, not from
 * the neighbour: the fixture in {@see NexusSlotDivergenceTest} predates the removal of the Nexus
 * envelope (task 1.1) and still carries `{operationId, payload}`. Do not align this one on it —
 * it is the old shape that is stale, not this one.
 */
final class PayloadForSlotTest extends TestCase
{
    public function testTheActivityPayloadIsReadFromInsideItsEnvelope(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->activityScheduled(5, 'act-1', 'weather', ['city' => 'Paris']),
        ]);

        // The arguments, not the envelope: returning the envelope would compare `activityName`
        // twice and let an argument change through.
        self::assertSame(['city' => 'Paris'], $history->activityPayloadForSlot(0));
    }

    public function testTheNexusPayloadIsReadNaked(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->nexusScheduled(5, 'payments', 'billing', 'collect', ['amount' => 90]),
        ]);

        self::assertSame(['amount' => 90], $history->nexusOperationPayloadForSlot(0));
    }

    public function testTheChildInputIsReadFromItsSinglePayload(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->childScheduled('child-1', 'ChargeCardWorkflow', ['sku' => 'ABC']),
        ]);

        self::assertSame(['sku' => 'ABC'], $history->childWorkflowInputForSlot(0));
    }

    public function testEachSlotKeepsItsOwnPayload(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->nexusScheduled(5, 'payments', 'billing', 'collect', ['amount' => 90]),
            $this->nexusScheduled(9, 'inventory', 'warehouse', 'reserve', ['sku' => 'ABC']),
            $this->childScheduled('child-1', 'A', ['n' => 1]),
            $this->childScheduled('child-2', 'B', ['n' => 2]),
        ]);

        self::assertSame(['amount' => 90], $history->nexusOperationPayloadForSlot(0));
        self::assertSame(['sku' => 'ABC'], $history->nexusOperationPayloadForSlot(1));
        self::assertSame(['n' => 1], $history->childWorkflowInputForSlot(0));
        self::assertSame(['n' => 2], $history->childWorkflowInputForSlot(1));
    }

    public function testASlotNobodyScheduledHasNoPayload(): void
    {
        // Null, not `[]`: "nothing recorded" disarms the guard, "scheduled without arguments"
        // does not.
        $history = TemporalExecutionHistory::fromEvents([]);

        self::assertNull($history->activityPayloadForSlot(0));
        self::assertNull($history->nexusOperationPayloadForSlot(0));
        self::assertNull($history->childWorkflowInputForSlot(0));
    }

    public function testAnEmptyPayloadIsRecordedAsEmptyNotAsAbsent(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->nexusScheduled(5, 'payments', 'billing', 'ping', []),
        ]);

        self::assertSame([], $history->nexusOperationPayloadForSlot(0));
    }

    public function testTheGuardRefusesANexusOperationWhosePayloadChanged(): void
    {
        // The wiring, not just the reading: this is where Nexus deserves the guard most — a
        // rescheduled activity lands back on one's own worker, a Nexus operation goes to a third
        // party, where the duplicate is theirs.
        $context = new ExecutionContext(
            'exec-nexus',
            TemporalExecutionHistory::fromEvents([
                $this->nexusScheduled(5, 'payments', 'billing', 'collect', ['amount' => 90]),
            ]),
            $this->createStub(WorkflowCommandBufferInterface::class),
        );

        try {
            $context->nexusOperation(
                NexusEndpoint::named('payments'),
                NexusService::named('billing'),
                NexusOperationName::named('collect'),
                ['amount' => 120],
            );
            self::fail('The payload divergence should have been refused.');
        } catch (WorkflowTaskFailure $refusal) {
            $message = $refusal->getMessage();
        }

        self::assertStringContainsString('Nexus operation slot 0', $message);
        self::assertStringContainsString('"payments/billing/collect" is still the same Nexus operation', $message);
    }

    public function testAFaithfulNexusReplayIsNotRefused(): void
    {
        $context = new ExecutionContext(
            'exec-nexus',
            TemporalExecutionHistory::fromEvents([
                $this->nexusScheduled(5, 'payments', 'billing', 'collect', ['amount' => 90]),
            ]),
            $this->createStub(WorkflowCommandBufferInterface::class),
        );

        $awaitable = $context->nexusOperation(
            NexusEndpoint::named('payments'),
            NexusService::named('billing'),
            NexusOperationName::named('collect'),
            ['amount' => 90],
        );

        self::assertNotNull($awaitable);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function activityScheduled(int $eventId, string $activityId, string $name, array $payload): HistoryEvent
    {
        $attrs = new ActivityTaskScheduledEventAttributes();
        $attrs->setActivityId($activityId);
        $attrs->setActivityType(new \Temporal\Api\Common\V1\ActivityType(['name' => $name]));
        $attrs->setInput(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode([
            'executionId' => 'exec-1',
            'activityId' => $activityId,
            'activityName' => $name,
            'payload' => $payload,
            'metadata' => [],
        ])));

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED);
        $event->setEventId($eventId);
        $event->setActivityTaskScheduledEventAttributes($attrs);

        return $event;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function nexusScheduled(int $eventId, string $endpoint, string $service, string $operation, array $payload): HistoryEvent
    {
        $attrs = new NexusOperationScheduledEventAttributes();
        $attrs->setEndpoint($endpoint);
        $attrs->setService($service);
        $attrs->setOperation($operation);
        // Naked, as the buffer writes it.
        $attrs->setInput(JsonPlainPayload::encode($payload));

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED);
        $event->setEventId($eventId);
        $event->setNexusOperationScheduledEventAttributes($attrs);

        return $event;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function childScheduled(string $workflowId, string $type, array $input): HistoryEvent
    {
        $attrs = new StartChildWorkflowExecutionInitiatedEventAttributes();
        $attrs->setWorkflowId($workflowId);
        $attrs->setWorkflowType(new WorkflowType(['name' => $type]));
        $attrs->setInput(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($input)));

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_START_CHILD_WORKFLOW_EXECUTION_INITIATED);
        $event->setStartChildWorkflowExecutionInitiatedEventAttributes($attrs);

        return $event;
    }
}
