<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\NexusOperationScheduledEventAttributes;

/**
 * The identity of a Nexus operation at replay, on the bridge side.
 *
 * Here and not on the journal side: that backend refuses Nexus operations by construction
 * (DUR036), so none of its histories carries one, and the guard would have nothing to compare
 * there.
 *
 * The identity is the **triple**. Routing the same service and the same operation to another
 * endpoint is a divergence, and comparing only the operation name would let it through — that is
 * the case this file holds.
 */
final class NexusSlotDivergenceTest extends TestCase
{
    public function testTheTripleIsRecoverableFromTheSlot(): void
    {
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(5, 'op-1', 'paiements', 'facturation', 'encaisser')]);

        self::assertSame('paiements/facturation/encaisser', $history->nexusOperationSignatureForSlot(0));
    }

    public function testASlotNobodyScheduledHasNoSignature(): void
    {
        $history = TemporalExecutionHistory::fromEvents([]);

        self::assertNull($history->nexusOperationSignatureForSlot(0));
    }

    public function testTheEndpointIsPartOfTheIdentity(): void
    {
        // The trap this test holds: identical service and operation, different endpoint. A guard
        // that compared only the operation would believe the replay faithful.
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(5, 'op-1', 'paiements', 'facturation', 'encaisser')]);

        self::assertNotSame('remboursements/facturation/encaisser', $history->nexusOperationSignatureForSlot(0));
    }

    public function testEachSlotKeepsItsOwnTriple(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->scheduled(5, 'op-1', 'paiements', 'facturation', 'encaisser'),
            $this->scheduled(9, 'op-2', 'stocks', 'entrepot', 'reserver'),
        ]);

        self::assertSame('paiements/facturation/encaisser', $history->nexusOperationSignatureForSlot(0));
        self::assertSame('stocks/entrepot/reserver', $history->nexusOperationSignatureForSlot(1));
    }

    private function scheduled(int $eventId, string $operationId, string $endpoint, string $service, string $operation): HistoryEvent
    {
        $attrs = new NexusOperationScheduledEventAttributes();
        $attrs->setEndpoint($endpoint);
        $attrs->setService($service);
        $attrs->setOperation($operation);
        $attrs->setInput(JsonPlainPayload::encode(['operationId' => $operationId, 'payload' => []]));

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED);
        $event->setEventId($eventId);
        $event->setNexusOperationScheduledEventAttributes($attrs);

        return $event;
    }
}
