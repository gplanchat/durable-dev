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
 * La charge d'une opération Nexus part telle que l'appelant l'a écrite.
 *
 * Mesuré avant d'être corrigé (tâche 1.1) : un gestionnaire servi par le SDK Go, appelé depuis un
 * workflow Durable, recevait `{"name":""}` et répondait « hello » au lieu de « hello ada ». Notre
 * enveloppe `{operationId, payload}` arrivait à un gestionnaire qui attendait la charge, il n'y
 * trouvait pas ses champs et prenait des valeurs zéro. **Rien ne levait** — ni le serveur, ni le SDK
 * Go, ni nous.
 *
 * La corrélation dont l'enveloppe servait de prétexte est déjà sur le fil : le serveur assigne un
 * `scheduledEventId` que l'événement de planification et les événements terminaux portent tous les
 * deux. C'est lui l'identité, et l'appelant n'a rien à ajouter à la charge de l'utilisateur.
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
            "un gestionnaire d'un autre SDK doit trouver ses champs au premier niveau",
        );
    }

    public function testAnOperationIsRecoveredFromItsScheduledEventId(): void
    {
        // L'identité que le serveur assigne, et que les événements terminaux portent.
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(5), $this->scheduled(9)]);

        self::assertSame('5', $history->findScheduledNexusOperation(0));
        self::assertSame('9', $history->findScheduledNexusOperation(1));
        self::assertNull($history->findScheduledNexusOperation(2));
    }

    public function testTheCancellationLookupStillFindsTheRealEventId(): void
    {
        // §4.2 en dépend : `RequestCancelNexusOperation` exige l'eventId réel, et un identifiant
        // qui ne correspond à rien fait rejeter la tâche par le serveur.
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(5)]);

        self::assertSame(5, $history->scheduledEventIdForNexusOperation('5'));
        self::assertNull($history->scheduledEventIdForNexusOperation('inconnue'));
    }

    public function testTheCallSiteIsStillRecoverableForFailures(): void
    {
        // Le triplet reste lisible : c'est ce qui nomme la divergence et ce qui dit d'où vient un
        // échec, et l'enveloppe n'y était pour rien.
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(5)]);

        self::assertSame('paiements/facturation/encaisser', $history->nexusOperationSignatureForSlot(0));
    }

    private function scheduled(int $eventId): HistoryEvent
    {
        $attrs = new NexusOperationScheduledEventAttributes();
        $attrs->setEndpoint('paiements');
        $attrs->setService('facturation');
        $attrs->setOperation('encaisser');
        // La charge de l'appelant, nue.
        $attrs->setInput(JsonPlainPayload::encode(['name' => 'ada']));

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED);
        $event->setEventId($eventId);
        $event->setNexusOperationScheduledEventAttributes($attrs);

        return $event;
    }
}
