<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\NexusOperationScheduledEventAttributes;

/**
 * The payload of a Nexus operation leaves exactly as the caller wrote it.
 *
 * Measured before being fixed (task 1.1): a handler served by the Go SDK, called from a Durable
 * workflow, received `{"name":""}` and answered "hello" instead of "hello ada". Our
 * `{operationId, payload}` envelope reached a handler that expected the payload, it did not find
 * its fields there and took zero values. **Nothing raised** — not the server, not the Go SDK, not
 * us.
 *
 * The correlation the envelope used as its pretext is already on the wire: the server assigns a
 * `scheduledEventId` that both the scheduling event and the terminal events carry. That one is the
 * identity, and the caller has nothing to add to the user's payload.
 */
final class NexusPayloadTravelsVerbatimTest extends TestCase
{
    public function testTheScheduledCommandCarriesTheCallersPayloadAndNothingElse(): void
    {
        $buffer = new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test-namespace'), 'exec-1');

        $buffer->scheduleNexusOperation(
            'peu-importe',
            NexusEndpoint::named('checkout-endpoint'),
            NexusService::named('com.example.checkout'),
            NexusOperationName::named('placeOrder'),
            ['name' => 'ada'],
            NexusOperationTimeouts::none(),
            NexusOperationHeaders::none(),
        );

        $commands = $buffer->flush();
        self::assertCount(1, $commands);

        $input = $commands[0]->getScheduleNexusOperationCommandAttributes()?->getInput();
        self::assertNotNull($input);

        self::assertSame(
            ['name' => 'ada'],
            JsonPlainPayload::decode($input),
            'a handler from another SDK must find its fields at the top level',
        );
    }

    public function testAnOperationIsRecoveredFromItsScheduledEventId(): void
    {
        // The identity the server assigns, and that the terminal events carry.
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(5), $this->scheduled(9)]);

        self::assertSame('5', $history->findScheduledNexusOperation(0));
        self::assertSame('9', $history->findScheduledNexusOperation(1));
        self::assertNull($history->findScheduledNexusOperation(2));
    }

    public function testTheCancellationLookupStillFindsTheRealEventId(): void
    {
        // §4.2 depends on it: `RequestCancelNexusOperation` requires the real eventId, and an
        // identifier that matches nothing makes the server reject the task.
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(5)]);

        self::assertSame(5, $history->scheduledEventIdForNexusOperation('5'));
        self::assertNull($history->scheduledEventIdForNexusOperation('inconnue'));
    }

    public function testTheCallSiteIsStillRecoverableForFailures(): void
    {
        // The triple stays readable: it is what names the divergence and what says where a
        // failure comes from, and the envelope had nothing to do with it.
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(5)]);

        self::assertSame('paiements/facturation/encaisser', $history->nexusOperationSignatureForSlot(0));
    }

    private function scheduled(int $eventId): HistoryEvent
    {
        $attrs = new NexusOperationScheduledEventAttributes();
        $attrs->setEndpoint('paiements');
        $attrs->setService('facturation');
        $attrs->setOperation('encaisser');
        // The caller's payload, bare.
        $attrs->setInput(JsonPlainPayload::encode(['name' => 'ada']));

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED);
        $event->setEventId($eventId);
        $event->setNexusOperationScheduledEventAttributes($attrs);

        return $event;
    }
}
