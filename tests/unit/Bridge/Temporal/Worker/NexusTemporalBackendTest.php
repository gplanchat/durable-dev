<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Nexus\DurableNexusOperationFailedException;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\NexusOperationCanceledEventAttributes;
use Temporal\Api\History\V1\NexusOperationCompletedEventAttributes;
use Temporal\Api\History\V1\NexusOperationFailedEventAttributes;
use Temporal\Api\History\V1\NexusOperationScheduledEventAttributes;
use Temporal\Api\History\V1\NexusOperationTimedOutEventAttributes;

/**
 * Le pont Temporal des opérations Nexus — §4.1, §4.2 et §4.3.
 *
 * Une découverte gouverne les trois : `NexusOperationScheduledEventAttributes` **ne porte aucune
 * identité fournie par l'appelant**, là où une activité porte son `activityId`. Les quatre
 * événements terminaux se réfèrent tous au `ScheduledEventId`. L'identité d'une opération est
 * donc positionnelle — le Nième événement `SCHEDULED` est le Nième appel — et devient, une fois
 * enregistrée, l'identifiant d'événement lui-même.
 */
#[RequiresPhpExtension('grpc')]
final class NexusTemporalBackendTest extends TestCase
{
    // -------------------------------------------------------------------------
    // §4.1 — la commande de planification
    // -------------------------------------------------------------------------

    public function testTheScheduleCommandCarriesTheNamesAndTheBounds(): void
    {
        $buffer = $this->buffer();
        $buffer->scheduleNexusOperation(
            'op-1',
            NexusEndpoint::named('payments'),
            NexusService::named('billing.v1.Billing'),
            NexusOperationName::named('charge'),
            ['order' => 'o-1'],
            new NexusOperationTimeouts(Duration::seconds(60), Duration::seconds(10), Duration::seconds(30)),
        );

        $commands = $buffer->flush();
        self::assertCount(1, $commands);
        self::assertSame(CommandType::COMMAND_TYPE_SCHEDULE_NEXUS_OPERATION, $commands[0]->getCommandType());

        $attrs = $commands[0]->getScheduleNexusOperationCommandAttributes();
        self::assertNotNull($attrs);
        self::assertSame('payments', $attrs->getEndpoint());
        self::assertSame('billing.v1.Billing', $attrs->getService());
        self::assertSame('charge', $attrs->getOperation());
        self::assertSame(['order' => 'o-1'], JsonPlainPayload::decode($attrs->getInput()));
        self::assertSame(60, (int) $attrs->getScheduleToCloseTimeout()?->getSeconds());
        self::assertSame(10, (int) $attrs->getScheduleToStartTimeout()?->getSeconds());
        self::assertSame(30, (int) $attrs->getStartToCloseTimeout()?->getSeconds());
    }

    public function testAnUnboundedOperationSendsNoBound(): void
    {
        // Le serveur n'exige aucune borne de fermeture pour Nexus (§1.3) : n'en inventer aucune.
        $buffer = $this->buffer();
        $buffer->scheduleNexusOperation(
            'op-1',
            NexusEndpoint::named('payments'),
            NexusService::named('billing.v1.Billing'),
            NexusOperationName::named('charge'),
            [],
            NexusOperationTimeouts::none(),
        );

        $attrs = $buffer->flush()[0]->getScheduleNexusOperationCommandAttributes();
        self::assertNull($attrs?->getScheduleToCloseTimeout());
        self::assertNull($attrs?->getScheduleToStartTimeout());
        self::assertNull($attrs?->getStartToCloseTimeout());
    }

    // -------------------------------------------------------------------------
    // §4.2 — l'annulation vise l'identifiant réel
    // -------------------------------------------------------------------------

    public function testCancellationUsesTheRealScheduledEventId(): void
    {
        // Un compteur inventé localement a déjà fait taire cette commande une fois, pour les
        // activités : l'identifiant vient de l'historique.
        $history = TemporalExecutionHistory::fromEvents([
            self::event(1, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED),
            self::scheduled(7, 'payments', 'billing.v1.Billing', 'charge'),
        ]);

        $buffer = $this->buffer($history);
        $buffer->cancelNexusOperation('7', 'race_superseded');

        $commands = $buffer->flush();
        self::assertCount(1, $commands);
        self::assertSame(CommandType::COMMAND_TYPE_REQUEST_CANCEL_NEXUS_OPERATION, $commands[0]->getCommandType());
        self::assertSame(7, (int) $commands[0]->getRequestCancelNexusOperationCommandAttributes()?->getScheduledEventId());
    }

    public function testCancellingAnOperationTheServerNeverSawEmitsNothing(): void
    {
        // Première passe : la commande de planification n'est pas encore partie, il n'y a rien à
        // annuler. Émettre une commande qui vise un identifiant inexistant ferait échouer la tâche.
        $buffer = $this->buffer(TemporalExecutionHistory::fromEvents([
            self::event(1, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED),
        ]));
        $buffer->cancelNexusOperation('un-uuid-local', 'race_superseded');

        self::assertSame([], $buffer->flush());
    }

    // -------------------------------------------------------------------------
    // §4.3 — relire les issues
    // -------------------------------------------------------------------------

    public function testTheScheduledEventIdIsTheOperationIdentityOnReplay(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            self::event(1, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED),
            self::scheduled(5, 'payments', 'billing.v1.Billing', 'charge'),
            self::scheduled(6, 'payments', 'billing.v1.Billing', 'refund'),
        ]);

        self::assertSame('5', $history->findScheduledNexusOperation(0));
        self::assertSame('6', $history->findScheduledNexusOperation(1));
        self::assertNull($history->findScheduledNexusOperation(2));
    }

    public function testACompletedOperationYieldsItsResult(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            self::event(1, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED),
            self::scheduled(5, 'payments', 'billing.v1.Billing', 'charge'),
            self::completed(6, 5, ['charged' => true]),
        ]);

        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot);
        self::assertNull($slot['failed']);
        self::assertSame(['charged' => true], $slot['result']);
    }

    /**
     * @return iterable<string, array{0: int, 1: HistoryEvent, 2: string}>
     */
    public static function terminalFailures(): iterable
    {
        yield 'échec de l’opération' => [5, self::failed(6, 5, 'declined'), DurableNexusOperationFailedException::KIND_OPERATION_FAILED];
        yield 'annulée' => [5, self::canceled(6, 5, 'cancelled by caller'), DurableNexusOperationFailedException::KIND_CANCELLED];
        yield 'échéance écoulée' => [5, self::timedOut(6, 5, 'deadline exceeded'), DurableNexusOperationFailedException::KIND_TIMED_OUT];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('terminalFailures')]
    public function testEachTerminalFailureKeepsItsOrigin(int $scheduledId, HistoryEvent $terminal, string $expectedKind): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            self::event(1, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED),
            self::scheduled($scheduledId, 'payments', 'billing.v1.Billing', 'charge'),
            $terminal,
        ]);

        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot);
        $failure = $slot['failed'];
        self::assertInstanceOf(DurableNexusOperationFailedException::class, $failure);
        self::assertSame($expectedKind, $failure->kind());
        self::assertSame('charge', $failure->operationName(), 'le nom de l’opération vient de son événement de planification');
    }

    public function testAnOperationStillInFlightHasNoOutcome(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            self::event(1, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED),
            self::scheduled(5, 'payments', 'billing.v1.Billing', 'charge'),
        ]);

        self::assertNull($history->findNexusOperationSlotResult(0));
    }

    // -------------------------------------------------------------------------

    private function buffer(?TemporalExecutionHistory $history = null): TemporalWorkflowCommandBuffer
    {
        return new TemporalWorkflowCommandBuffer(
            new TemporalConnection('localhost:7233', 'test-namespace'),
            'exec-1',
            $history,
        );
    }

    private static function event(int $id, int $type): HistoryEvent
    {
        $event = new HistoryEvent();
        $event->setEventId($id);
        $event->setEventType($type);

        return $event;
    }

    private static function scheduled(int $id, string $endpoint, string $service, string $operation): HistoryEvent
    {
        $event = self::event($id, EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED);
        $attrs = new NexusOperationScheduledEventAttributes();
        $attrs->setEndpoint($endpoint);
        $attrs->setService($service);
        $attrs->setOperation($operation);
        $event->setNexusOperationScheduledEventAttributes($attrs);

        return $event;
    }

    private static function completed(int $id, int $scheduledEventId, mixed $result): HistoryEvent
    {
        $event = self::event($id, EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED);
        $attrs = new NexusOperationCompletedEventAttributes();
        $attrs->setScheduledEventId($scheduledEventId);
        $attrs->setResult(JsonPlainPayload::encode($result));
        $event->setNexusOperationCompletedEventAttributes($attrs);

        return $event;
    }

    private static function failed(int $id, int $scheduledEventId, string $message): HistoryEvent
    {
        $event = self::event($id, EventType::EVENT_TYPE_NEXUS_OPERATION_FAILED);
        $attrs = new NexusOperationFailedEventAttributes();
        $attrs->setScheduledEventId($scheduledEventId);
        $attrs->setFailure(new Failure(['message' => $message]));
        $event->setNexusOperationFailedEventAttributes($attrs);

        return $event;
    }

    private static function canceled(int $id, int $scheduledEventId, string $message): HistoryEvent
    {
        $event = self::event($id, EventType::EVENT_TYPE_NEXUS_OPERATION_CANCELED);
        $attrs = new NexusOperationCanceledEventAttributes();
        $attrs->setScheduledEventId($scheduledEventId);
        $attrs->setFailure(new Failure(['message' => $message]));
        $event->setNexusOperationCanceledEventAttributes($attrs);

        return $event;
    }

    private static function timedOut(int $id, int $scheduledEventId, string $message): HistoryEvent
    {
        $event = self::event($id, EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT);
        $attrs = new NexusOperationTimedOutEventAttributes();
        $attrs->setScheduledEventId($scheduledEventId);
        $attrs->setFailure(new Failure(['message' => $message]));
        $event->setNexusOperationTimedOutEventAttributes($attrs);

        return $event;
    }
}
