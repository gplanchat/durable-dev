<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\Exception\DurableNexusOperationFailedException;
use Gplanchat\Durable\Nexus\NexusOperationFailureKind;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Temporal\Api\History\V1\NexusOperationStartedEventAttributes;
use Temporal\Api\History\V1\NexusOperationTimedOutEventAttributes;

/**
 * Relire les événements Nexus, et annuler ce qu'ils identifient — §4.2, §4.3, §4.5.
 *
 * L'identité d'une opération voyage dans sa charge utile, comme la commande de planification
 * l'écrit déjà : `NexusOperationScheduledEventAttributes` n'a aucun champ pour elle, et le fil ne
 * porte qu'un `Payload`. C'est donc de là qu'elle se relit, et l'identifiant d'événement reste ce
 * que la commande d'annulation doit viser.
 */
#[RequiresPhpExtension('grpc')]
final class NexusHistoryAndCancellationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // §4.3 — relire l'identité et les issues
    // -------------------------------------------------------------------------

    public function testTheOperationIdentityIsReadBackFromItsPayload(): void
    {
        $history = $this->historyOf([
            self::scheduled(5, 'op-a', 'charge'),
            self::scheduled(6, 'op-b', 'refund'),
        ]);

        self::assertSame('op-a', $history->findScheduledNexusOperation(0));
        self::assertSame('op-b', $history->findScheduledNexusOperation(1));
        self::assertNull($history->findScheduledNexusOperation(2));
    }

    public function testACompletedOperationYieldsTheCallersPayloadNotTheEnvelope(): void
    {
        // L'enveloppe {operationId, payload} est notre comptabilité : le workflow doit recevoir
        // ce que l'endpoint a répondu, pas notre emballage.
        $history = $this->historyOf([
            self::scheduled(5, 'op-a', 'charge'),
            self::completed(6, 5, ['charged' => true]),
        ]);

        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot);
        self::assertNull($slot['failed']);
        self::assertSame(['charged' => true], $slot['result']);
    }

    /**
     * @return iterable<string, array{0: HistoryEvent, 1: NexusOperationFailureKind}>
     */
    public static function terminalFailures(): iterable
    {
        yield 'refus de l’opération' => [self::failed(6, 5, 'declined'), NexusOperationFailureKind::OperationFailed];
        yield 'annulation' => [self::canceled(6, 5, 'cancelled'), NexusOperationFailureKind::Cancellation];
        yield 'échéance' => [self::timedOut(6, 5, 'deadline exceeded'), NexusOperationFailureKind::Timeout];
    }

    #[DataProvider('terminalFailures')]
    public function testEachTerminalFailureKeepsItsNatureAndItsCallSite(HistoryEvent $terminal, NexusOperationFailureKind $expected): void
    {
        $history = $this->historyOf([self::scheduled(5, 'op-a', 'charge'), $terminal]);

        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot);
        $failure = $slot['failed'];
        self::assertInstanceOf(DurableNexusOperationFailedException::class, $failure);
        self::assertSame($expected, $failure->kind());
        // Le site d'appel, sans lequel un workflow qui parle à trois endpoints tombe sans dire lequel.
        self::assertSame('payments', $failure->endpoint());
        self::assertSame('billing.v1.Billing', $failure->service());
        self::assertSame('charge', $failure->operation());
    }

    public function testAnOperationStillInFlightHasNoOutcome(): void
    {
        $history = $this->historyOf([self::scheduled(5, 'op-a', 'charge')]);

        self::assertNull($history->findNexusOperationSlotResult(0));
    }

    // -------------------------------------------------------------------------
    // §4.5 — l'asynchrone est hors périmètre, et le dit
    // -------------------------------------------------------------------------

    public function testAnAsynchronousStartIsRefusedRatherThanAwaitedForever(): void
    {
        $history = $this->historyOf([
            self::scheduled(5, 'op-a', 'charge'),
            self::started(6, 5, 'un-jeton'),
        ]);

        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot, 'réglée en échec, plutôt que laissée en vol sans trace');
        $failure = $slot['failed'];
        self::assertInstanceOf(DurableNexusOperationFailedException::class, $failure);
        self::assertSame(NexusOperationFailureKind::HandlerError, $failure->kind());
        self::assertStringContainsString('asynchron', $failure->getMessage());
    }

    public function testASynchronousStartChangesNothing(): void
    {
        $history = $this->historyOf([
            self::scheduled(5, 'op-a', 'charge'),
            self::started(6, 5, ''),
            self::completed(7, 5, 'ok'),
        ]);

        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot);
        self::assertNull($slot['failed']);
        self::assertSame('ok', $slot['result']);
    }

    // -------------------------------------------------------------------------
    // §4.2 — annuler vise l'identifiant d'événement réel
    // -------------------------------------------------------------------------

    public function testCancellationTargetsTheRealScheduledEventId(): void
    {
        $history = $this->historyOf([self::scheduled(7, 'op-a', 'charge')]);
        $buffer = new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'ns'), 'exec-1', $history);

        $buffer->cancelNexusOperation('op-a', 'race_superseded');

        $commands = $buffer->flush();
        self::assertCount(1, $commands);
        self::assertSame(CommandType::COMMAND_TYPE_REQUEST_CANCEL_NEXUS_OPERATION, $commands[0]->getCommandType());
        self::assertSame(7, (int) $commands[0]->getRequestCancelNexusOperationCommandAttributes()?->getScheduledEventId());
    }

    public function testCancellingWhatTheServerNeverSawEmitsNothing(): void
    {
        // Première passe : la planification n'est pas encore partie. Viser un identifiant
        // inexistant ferait échouer la tâche de workflow.
        $history = $this->historyOf([]);
        $buffer = new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'ns'), 'exec-1', $history);

        $buffer->cancelNexusOperation('op-jamais-vue', 'race_superseded');

        self::assertSame([], $buffer->flush());
    }

    // -------------------------------------------------------------------------

    /** @param list<HistoryEvent> $events */
    private function historyOf(array $events): TemporalExecutionHistory
    {
        return TemporalExecutionHistory::fromEvents([
            self::event(1, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED),
            ...$events,
        ]);
    }

    private static function event(int $id, int $type): HistoryEvent
    {
        $event = new HistoryEvent();
        $event->setEventId($id);
        $event->setEventType($type);

        return $event;
    }

    private static function scheduled(int $id, string $operationId, string $operation): HistoryEvent
    {
        $event = self::event($id, EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED);
        $attrs = new NexusOperationScheduledEventAttributes();
        $attrs->setEndpoint('payments');
        $attrs->setService('billing.v1.Billing');
        $attrs->setOperation($operation);
        // La forme que la commande de planification écrit déjà.
        $attrs->setInput(JsonPlainPayload::encode(['operationId' => $operationId, 'payload' => ['order' => 'o-1']]));
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

    private static function started(int $id, int $scheduledEventId, string $token): HistoryEvent
    {
        $event = self::event($id, EventType::EVENT_TYPE_NEXUS_OPERATION_STARTED);
        $attrs = new NexusOperationStartedEventAttributes();
        $attrs->setScheduledEventId($scheduledEventId);
        $attrs->setOperationToken($token);
        $event->setNexusOperationStartedEventAttributes($attrs);

        return $event;
    }
}
