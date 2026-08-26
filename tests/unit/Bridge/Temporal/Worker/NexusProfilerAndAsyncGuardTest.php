<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Profiler\TemporalEventConverter;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Durable\Event\NexusOperationCompleted;
use Gplanchat\Durable\Event\NexusOperationFailed;
use Gplanchat\Durable\Event\NexusOperationScheduled;
use Gplanchat\Durable\Nexus\DurableNexusOperationFailedException;
use Gplanchat\Durable\Nexus\NexusAsynchronousOperationUnsupportedException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\NexusOperationCompletedEventAttributes;
use Temporal\Api\History\V1\NexusOperationFailedEventAttributes;
use Temporal\Api\History\V1\NexusOperationScheduledEventAttributes;
use Temporal\Api\History\V1\NexusOperationStartedEventAttributes;

/**
 * Ce que le profileur montre d'une opération Nexus, et la limite qu'on refuse de taire — §4.4, §4.5.
 */
#[RequiresPhpExtension('grpc')]
final class NexusProfilerAndAsyncGuardTest extends TestCase
{
    // -------------------------------------------------------------------------
    // §4.4 — le profileur voit les opérations
    // -------------------------------------------------------------------------

    public function testASchedulingIsConvertedWithItsThreeNames(): void
    {
        // Sans conversion, une opération Nexus est un trou dans la trace : le profileur ne montre
        // que ce qu'on lui traduit, et un appel invisible est un appel qu'on ne diagnostique pas.
        $converter = new TemporalEventConverter('exec-1');
        $event = $converter->convert(self::scheduled(5, 'payments', 'billing.v1.Billing', 'charge'));

        self::assertInstanceOf(NexusOperationScheduled::class, $event);
        self::assertSame('5', $event->operationId());
        self::assertSame('payments', $event->endpoint());
        self::assertSame('billing.v1.Billing', $event->service());
        self::assertSame('charge', $event->operationName());
    }

    public function testACompletionIsConvertedWithItsResult(): void
    {
        $converter = new TemporalEventConverter('exec-1');
        $converter->convert(self::scheduled(5, 'payments', 'billing.v1.Billing', 'charge'));
        $event = $converter->convert(self::completed(6, 5, ['charged' => true]));

        self::assertInstanceOf(NexusOperationCompleted::class, $event);
        self::assertSame('5', $event->operationId());
        self::assertSame(['charged' => true], $event->result());
    }

    public function testAFailureKeepsItsOriginInTheTrace(): void
    {
        $converter = new TemporalEventConverter('exec-1');
        $converter->convert(self::scheduled(5, 'payments', 'billing.v1.Billing', 'charge'));
        $event = $converter->convert(self::failed(6, 5, 'declined'));

        self::assertInstanceOf(NexusOperationFailed::class, $event);
        self::assertSame(DurableNexusOperationFailedException::KIND_OPERATION_FAILED, $event->kind());
        self::assertStringContainsString('declined', $event->failureMessage());
    }

    // -------------------------------------------------------------------------
    // §4.5 — une opération asynchrone est hors périmètre, et le dit
    // -------------------------------------------------------------------------

    public function testAnAsynchronousOperationIsRefusedRatherThanAwaitedForever(): void
    {
        // Un jeton sur `STARTED` signale une opération que l'endpoint complétera plus tard, par un
        // autre canal. Cet incrément ne sait pas la suivre : la taire ferait attendre le workflow
        // sans fin, et sans rien dans les journaux.
        $history = TemporalExecutionHistory::fromEvents([
            self::event(1, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED),
            self::scheduled(5, 'payments', 'billing.v1.Billing', 'charge'),
            self::startedWithToken(6, 5, 'un-jeton-asynchrone'),
        ]);

        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot, 'l’opération doit être vue comme réglée, en échec');
        self::assertInstanceOf(NexusAsynchronousOperationUnsupportedException::class, $slot['failed']);
        self::assertStringContainsString('charge', $slot['failed']->getMessage());
    }

    public function testASynchronousStartIsNotRefused(): void
    {
        // `STARTED` sans jeton est le cas courant : l'opération démarre et se complétera dans la
        // même conversation. Rien à signaler.
        $history = TemporalExecutionHistory::fromEvents([
            self::event(1, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED),
            self::scheduled(5, 'payments', 'billing.v1.Billing', 'charge'),
            self::startedWithToken(6, 5, ''),
            self::completed(7, 5, 'ok'),
        ]);

        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot);
        self::assertNull($slot['failed']);
        self::assertSame('ok', $slot['result']);
    }

    // -------------------------------------------------------------------------

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

    private static function startedWithToken(int $id, int $scheduledEventId, string $token): HistoryEvent
    {
        $event = self::event($id, EventType::EVENT_TYPE_NEXUS_OPERATION_STARTED);
        $attrs = new NexusOperationStartedEventAttributes();
        $attrs->setScheduledEventId($scheduledEventId);
        $attrs->setOperationToken($token);
        $event->setNexusOperationStartedEventAttributes($attrs);

        return $event;
    }
}
