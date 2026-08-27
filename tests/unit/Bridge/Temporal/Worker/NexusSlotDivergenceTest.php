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
 * L'identité d'une opération Nexus au replay, côté pont.
 *
 * Ici et pas côté journal : ce backend refuse les opérations Nexus par construction (DUR036), donc
 * aucun de ses historiques n'en porte, et la garde n'y aurait rien à comparer.
 *
 * L'identité est le **triplet**. Router le même service et la même opération vers un autre
 * endpoint est une divergence, et ne comparer que le nom de l'opération la laisserait passer —
 * c'est le cas que ce fichier tient.
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
        // Le piège que ce test tient : service et opération identiques, endpoint différent. Une
        // garde qui ne comparerait que l'opération croirait le replay fidèle.
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
