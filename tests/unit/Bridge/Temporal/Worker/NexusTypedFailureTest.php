<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Durable\Exception\DurableNexusOperationFailedException;
use Gplanchat\Durable\Nexus\NexusOperationFailureKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\NexusOperationCanceledEventAttributes;
use Temporal\Api\History\V1\NexusOperationFailedEventAttributes;
use Temporal\Api\History\V1\NexusOperationScheduledEventAttributes;
use Temporal\Api\History\V1\NexusOperationTimedOutEventAttributes;

/**
 * Le chaînon manquant entre §3.6 et §4.3.
 *
 * §3.6 a construit `DurableNexusOperationFailedException`, qui distingue quatre natures et nomme le
 * site d'appel ; §4.3 a appris à relire les événements. Mais la lecture rendait des
 * `RuntimeException` nues : l'exception typée existait sans que rien ne la lève jamais, et sa
 * branche dans le classificateur était donc morte.
 *
 * Ce que le spec exige, et que ce test tient : « The failure SHALL carry the endpoint, service and
 * operation names so an unhandled one names the call site. »
 */
final class NexusTypedFailureTest extends TestCase
{
    /**
     * @return iterable<string, array{int, NexusOperationFailureKind}>
     */
    public static function terminalFailures(): iterable
    {
        yield 'échec' => [EventType::EVENT_TYPE_NEXUS_OPERATION_FAILED, NexusOperationFailureKind::OperationFailed];
        yield 'borne dépassée' => [EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT, NexusOperationFailureKind::Timeout];
        yield 'annulation' => [EventType::EVENT_TYPE_NEXUS_OPERATION_CANCELED, NexusOperationFailureKind::Cancellation];
    }

    #[DataProvider('terminalFailures')]
    public function testEachEndingCarriesItsKindAndItsCallSite(int $type, NexusOperationFailureKind $expected): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->scheduled(5, 'op-un'),
            $this->terminal($type, 7),
        ]);

        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot);

        $failure = $slot['failed'];
        self::assertInstanceOf(DurableNexusOperationFailedException::class, $failure);
        self::assertSame($expected, $failure->kind(), 'la nature de la fin doit survivre à la lecture');
        self::assertSame('paiements', $failure->endpoint());
        self::assertSame('facturation', $failure->service());
        self::assertSame('encaisser', $failure->operation());
    }

    public function testAnUnhandledFailureNamesItsOriginThroughTheClassifier(): void
    {
        // Le bout de la chaîne : ce que voit celui qui lit un workflow tombé.
        $history = TemporalExecutionHistory::fromEvents([
            $this->scheduled(5, 'op-un'),
            $this->terminal(EventType::EVENT_TYPE_NEXUS_OPERATION_FAILED, 7),
        ]);
        $failure = $history->findNexusOperationSlotResult(0)['failed'];

        $classified = \Gplanchat\Durable\Failure\WorkflowFailureClassifier::classify('exec-1', $failure);

        self::assertSame(
            \Gplanchat\Durable\Event\WorkflowExecutionFailed::KIND_UNHANDLED_NEXUS_OPERATION,
            $classified->kind(),
        );
        self::assertSame('paiements', $classified->context()['endpoint'] ?? null);
    }

    private function scheduled(int $eventId, string $operationId): HistoryEvent
    {
        $attrs = new NexusOperationScheduledEventAttributes();
        $attrs->setEndpoint('paiements');
        $attrs->setService('facturation');
        $attrs->setOperation('encaisser');
        $attrs->setInput(JsonPlainPayload::encode(['operationId' => $operationId, 'payload' => []]));

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED);
        $event->setEventId($eventId);
        $event->setNexusOperationScheduledEventAttributes($attrs);

        return $event;
    }

    private function terminal(int $type, int $eventId): HistoryEvent
    {
        $event = new HistoryEvent();
        $event->setEventType($type);
        $event->setEventId($eventId);
        match ($type) {
            EventType::EVENT_TYPE_NEXUS_OPERATION_FAILED => $event->setNexusOperationFailedEventAttributes((new NexusOperationFailedEventAttributes())->setScheduledEventId(5)),
            EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT => $event->setNexusOperationTimedOutEventAttributes((new NexusOperationTimedOutEventAttributes())->setScheduledEventId(5)),
            default => $event->setNexusOperationCanceledEventAttributes((new NexusOperationCanceledEventAttributes())->setScheduledEventId(5)),
        };

        return $event;
    }
}
