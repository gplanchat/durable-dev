<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\NexusOperationCanceledEventAttributes;
use Temporal\Api\History\V1\NexusOperationCompletedEventAttributes;
use Temporal\Api\History\V1\NexusOperationFailedEventAttributes;
use Temporal\Api\History\V1\NexusOperationScheduledEventAttributes;
use Temporal\Api\History\V1\NexusOperationTimedOutEventAttributes;

/**
 * §4.3 — relire les événements `NEXUS_OPERATION_*` depuis l'historique Temporal.
 *
 * C'est ce qui manque pour que le replay retombe sur l'opération déjà lancée. Tant que la lecture
 * n'existe pas, `findScheduledNexusOperation()` ne peut pas rendre `null` sans danger : le
 * contexte n'émet la commande que si le slot est vide, donc un `null` systématique replanifie
 * l'opération à **chaque passe** — et une opération Nexus qui repart est facturée à chaque fois.
 * C'est pourquoi le stub levait plutôt que de rendre `null`.
 */
final class NexusHistoryReadingTest extends TestCase
{
    public function testAScheduledOperationIsFoundAtItsSlot(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->scheduled(5, 'op-un'),
            $this->scheduled(6, 'op-deux'),
        ]);

        self::assertSame('op-un', $history->findScheduledNexusOperation(0));
        self::assertSame('op-deux', $history->findScheduledNexusOperation(1));
        self::assertNull($history->findScheduledNexusOperation(2));
    }

    public function testAnOperationStillInFlightHasNoResultYet(): void
    {
        // La distinction qui compte : « planifiée » n'est pas « réglée ». Confondre les deux
        // ferait conclure le workflow sur une opération qui n'a pas répondu.
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(5, 'op-un')]);

        self::assertSame('op-un', $history->findScheduledNexusOperation(0));
        self::assertNull($history->findNexusOperationSlotResult(0));
    }

    public function testACompletedOperationRendersItsResult(): void
    {
        $completed = new NexusOperationCompletedEventAttributes();
        $completed->setScheduledEventId(5);
        $completed->setResult((new Payload())->setData(JsonPlainPayload::encode(['montant' => 42])->getData()));

        $history = TemporalExecutionHistory::fromEvents([
            $this->scheduled(5, 'op-un'),
            $this->event(EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED, 7, static fn(HistoryEvent $e) => $e->setNexusOperationCompletedEventAttributes($completed)),
        ]);

        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot);
        self::assertNull($slot['failed']);
        self::assertSame(['montant' => 42], $slot['result']);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function terminalFailures(): iterable
    {
        yield 'échec' => [EventType::EVENT_TYPE_NEXUS_OPERATION_FAILED, 'failed'];
        yield 'dépassement de borne' => [EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT, 'timeout'];
        yield 'annulation' => [EventType::EVENT_TYPE_NEXUS_OPERATION_CANCELED, 'cancellation'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('terminalFailures')]
    public function testEveryNonSuccessfulEndingSurfacesAsAFailure(int $type, string $needle): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->scheduled(5, 'op-un'),
            $this->event($type, 7, static function (HistoryEvent $e) use ($type): void {
                match ($type) {
                    EventType::EVENT_TYPE_NEXUS_OPERATION_FAILED => $e->setNexusOperationFailedEventAttributes((new NexusOperationFailedEventAttributes())->setScheduledEventId(5)),
                    EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT => $e->setNexusOperationTimedOutEventAttributes((new NexusOperationTimedOutEventAttributes())->setScheduledEventId(5)),
                    default => $e->setNexusOperationCanceledEventAttributes((new NexusOperationCanceledEventAttributes())->setScheduledEventId(5)),
                };
            }),
        ]);

        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot);
        self::assertInstanceOf(\Throwable::class, $slot['failed']);
        self::assertStringContainsStringIgnoringCase($needle, $slot['failed']->getMessage());

        // Et l'origine est nommée : c'est ce que §6.4 exige d'un échec Nexus, et ce qui distingue
        // « l'opération a échoué » de « quelle opération, chez qui ».
        $message = $slot['failed']->getMessage();
        self::assertStringContainsString('encaisser', $message);
        self::assertStringContainsString('facturation', $message);
        self::assertStringContainsString('paiements', $message);
    }

    public function testTheScheduledEventIdIsRecoverableForCancellation(): void
    {
        // §4.2 en dépend : `RequestCancelNexusOperation` exige l'eventId réel, et un identifiant
        // qui ne correspond à rien fait rejeter la tâche par le serveur.
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(5, 'op-un')]);

        self::assertSame(5, $history->scheduledEventIdForNexusOperation('op-un'));
        self::assertNull($history->scheduledEventIdForNexusOperation('inconnue'));
    }

    public function testCancellingUsesTheRealScheduledEventId(): void
    {
        // §4.2 : la commande d'annulation ne part que si l'historique connaît l'opération.
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(5, 'op-un')]);
        $buffer = new \Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer(
            new \Gplanchat\Bridge\Temporal\TemporalConnection('localhost:7233', 'test'),
            'exec-1',
            $history,
        );

        $buffer->cancelNexusOperation('op-un', 'race_superseded');
        $commands = $buffer->flush();

        self::assertCount(1, $commands);
        self::assertSame(
            \Temporal\Api\Enums\V1\CommandType::COMMAND_TYPE_REQUEST_CANCEL_NEXUS_OPERATION,
            $commands[0]->getCommandType(),
        );
        self::assertSame(5, $commands[0]->getRequestCancelNexusOperationCommandAttributes()?->getScheduledEventId());
    }

    public function testCancellingAnUnknownOperationEmitsNothing(): void
    {
        // Un eventId inventé ferait rejeter la tâche entière par le serveur : mieux vaut se taire.
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(5, 'op-un')]);
        $buffer = new \Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer(
            new \Gplanchat\Bridge\Temporal\TemporalConnection('localhost:7233', 'test'),
            'exec-1',
            $history,
        );

        $buffer->cancelNexusOperation('jamais-planifiee', 'race_superseded');

        self::assertSame([], $buffer->flush());
    }

    private function scheduled(int $eventId, string $operationId): HistoryEvent
    {
        $attrs = new NexusOperationScheduledEventAttributes();
        $attrs->setEndpoint('paiements');
        $attrs->setService('facturation');
        $attrs->setOperation('encaisser');
        // L'identité applicative voyage dans le payload d'entrée, faute de champ dédié côté
        // Temporal — c'est ce que le tampon de commandes y met.
        $attrs->setInput(JsonPlainPayload::encode(['operationId' => $operationId, 'payload' => []]));

        return $this->event(EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED, $eventId, static fn(HistoryEvent $e) => $e->setNexusOperationScheduledEventAttributes($attrs));
    }

    private function event(int $type, int $eventId, callable $fill): HistoryEvent
    {
        $event = new HistoryEvent();
        $event->setEventType($type);
        $event->setEventId($eventId);
        $fill($event);

        return $event;
    }
}
