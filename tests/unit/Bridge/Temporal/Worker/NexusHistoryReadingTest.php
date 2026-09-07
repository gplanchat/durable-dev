<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Durable\Exception\DurableNexusOperationFailedException;
use Gplanchat\Durable\Nexus\NexusOperationFailureKind;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\NexusOperationCanceledEventAttributes;
use Temporal\Api\History\V1\NexusOperationCompletedEventAttributes;
use Temporal\Api\History\V1\NexusOperationFailedEventAttributes;
use Temporal\Api\History\V1\NexusOperationScheduledEventAttributes;
use Temporal\Api\History\V1\NexusOperationStartedEventAttributes;
use Temporal\Api\History\V1\NexusOperationTimedOutEventAttributes;

/**
 * §4.3 — reading the `NEXUS_OPERATION_*` events back from the Temporal history.
 *
 * This is what is missing for replay to land back on the operation already launched. As long as
 * the reading does not exist, `findScheduledNexusOperation()` cannot return `null` safely: the
 * context emits the command only if the slot is empty, so a systematic `null` reschedules the
 * operation on **every pass** — and a Nexus operation that starts again is billed every time.
 * That is why the stub raised rather than returning `null`.
 */
final class NexusHistoryReadingTest extends TestCase
{
    public function testAScheduledOperationIsFoundAtItsSlot(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->scheduled(5),
            $this->scheduled(6),
        ]);

        self::assertSame('5', $history->findScheduledNexusOperation(0));
        self::assertSame('6', $history->findScheduledNexusOperation(1));
        self::assertNull($history->findScheduledNexusOperation(2));
    }

    public function testAnOperationStillInFlightHasNoResultYet(): void
    {
        // The distinction that matters: "scheduled" is not "settled". Confusing the two would
        // make the workflow conclude on an operation that has not answered.
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(5)]);

        self::assertSame('5', $history->findScheduledNexusOperation(0));
        self::assertNull($history->findNexusOperationSlotResult(0));
    }

    public function testACompletedOperationRendersItsResult(): void
    {
        $completed = new NexusOperationCompletedEventAttributes();
        $completed->setScheduledEventId(5);
        $completed->setResult((new Payload())->setData(JsonPlainPayload::encode(['montant' => 42])->getData()));

        $history = TemporalExecutionHistory::fromEvents([
            $this->scheduled(5),
            $this->event(EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED, 7, static fn(HistoryEvent $e) => $e->setNexusOperationCompletedEventAttributes($completed)),
        ]);

        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot);
        self::assertNull($slot['failed']);
        self::assertSame(['montant' => 42], $slot['result']);
    }

    /**
     * @return iterable<string, array{int, NexusOperationFailureKind}>
     */
    public static function terminalFailures(): iterable
    {
        yield 'échec' => [EventType::EVENT_TYPE_NEXUS_OPERATION_FAILED, NexusOperationFailureKind::OperationFailed];
        yield 'dépassement de borne' => [EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT, NexusOperationFailureKind::Timeout];
        yield 'annulation' => [EventType::EVENT_TYPE_NEXUS_OPERATION_CANCELED, NexusOperationFailureKind::Cancellation];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('terminalFailures')]
    public function testEveryNonSuccessfulEndingSurfacesAsAFailure(int $type, NexusOperationFailureKind $expected): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->scheduled(5),
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
        // The kind, not the wording: a message is a phrasing, a kind is a contract.
        self::assertInstanceOf(DurableNexusOperationFailedException::class, $slot['failed']);
        self::assertSame($expected, $slot['failed']->kind());
    }

    public function testTheScheduledEventIdIsRecoverableForCancellation(): void
    {
        // §4.2 depends on it: `RequestCancelNexusOperation` requires the real eventId, and an
        // identifier that matches nothing makes the server reject the task.
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(5)]);

        self::assertSame(5, $history->scheduledEventIdForNexusOperation('5'));
        self::assertNull($history->scheduledEventIdForNexusOperation('inconnue'));
    }

    public function testCancellingUsesTheRealScheduledEventId(): void
    {
        // §4.2: the cancellation command only leaves if the history knows the operation.
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(5)]);
        $buffer = new \Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer(
            new \Gplanchat\Bridge\Temporal\TemporalConnection('localhost:7233', 'test'),
            'exec-1',
            $history,
        );

        $buffer->cancelNexusOperation('5', 'race_superseded');
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
        // An invented eventId would make the server reject the whole task: better to stay silent.
        $history = TemporalExecutionHistory::fromEvents([$this->scheduled(5)]);
        $buffer = new \Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer(
            new \Gplanchat\Bridge\Temporal\TemporalConnection('localhost:7233', 'test'),
            'exec-1',
            $history,
        );

        $buffer->cancelNexusOperation('jamais-planifiee', 'race_superseded');

        self::assertSame([], $buffer->flush());
    }

    public function testAnOperationStartedWithATokenIsStillInFlight(): void
    {
        // 1.4 measured what the server does: it records NEXUS_OPERATION_STARTED, sets
        // `callback: temporal://system`, and correlates the completion onto this execution itself.
        // The token says "the handler will answer later", not "nobody will answer".
        // Refusing here means refusing the whole asynchronous form.
        $started = new NexusOperationStartedEventAttributes();
        $started->setScheduledEventId(5);
        $started->setOperationToken('jeton-asynchrone');

        $history = TemporalExecutionHistory::fromEvents([
            $this->scheduled(5),
            $this->event(EventType::EVENT_TYPE_NEXUS_OPERATION_STARTED, 7, static fn(HistoryEvent $e) => $e->setNexusOperationStartedEventAttributes($started)),
        ]);

        self::assertNull(
            $history->findNexusOperationSlotResult(0),
            'An asynchronous operation that has started is in flight, not failed.',
        );
    }

    public function testAnAsynchronousOperationRendersTheResultTheServerDelivers(): void
    {
        // The server correlates by `scheduledEventId`: the completion arrives on this execution,
        // long after the start, and it is what settles the slot.
        $started = new NexusOperationStartedEventAttributes();
        $started->setScheduledEventId(5);
        $started->setOperationToken('jeton-asynchrone');
        $completed = new NexusOperationCompletedEventAttributes();
        $completed->setScheduledEventId(5);
        $completed->setResult((new Payload())->setData(JsonPlainPayload::encode(['montant' => 42])->getData()));

        $history = TemporalExecutionHistory::fromEvents([
            $this->scheduled(5),
            $this->event(EventType::EVENT_TYPE_NEXUS_OPERATION_STARTED, 7, static fn(HistoryEvent $e) => $e->setNexusOperationStartedEventAttributes($started)),
            $this->event(EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED, 12, static fn(HistoryEvent $e) => $e->setNexusOperationCompletedEventAttributes($completed)),
        ]);

        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot);
        self::assertNull($slot['failed'], 'The asynchronous start must no longer leave a failure behind it.');
        self::assertSame(['montant' => 42], $slot['result']);
    }

    public function testAnAsynchronousOperationThatFailsIsClassifiedLikeAnyOther(): void
    {
        // 3.3: the failure delivered by callback is of no other kind than the synchronous one.
        $started = new NexusOperationStartedEventAttributes();
        $started->setScheduledEventId(5);
        $started->setOperationToken('jeton-asynchrone');
        $failed = new NexusOperationFailedEventAttributes();
        $failed->setScheduledEventId(5);

        $history = TemporalExecutionHistory::fromEvents([
            $this->scheduled(5),
            $this->event(EventType::EVENT_TYPE_NEXUS_OPERATION_STARTED, 7, static fn(HistoryEvent $e) => $e->setNexusOperationStartedEventAttributes($started)),
            $this->event(EventType::EVENT_TYPE_NEXUS_OPERATION_FAILED, 12, static fn(HistoryEvent $e) => $e->setNexusOperationFailedEventAttributes($failed)),
        ]);

        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot);
        self::assertInstanceOf(DurableNexusOperationFailedException::class, $slot['failed']);
        self::assertSame(NexusOperationFailureKind::OperationFailed, $slot['failed']->kind());
    }

    public function testAnOperationStartedWithoutATokenIsNotAFailure(): void
    {
        // Without a token, the operation is synchronous: it has started, it will answer on this
        // execution, and there is nothing to report. Confusing the two would refuse the nominal
        // case.
        $started = new NexusOperationStartedEventAttributes();
        $started->setScheduledEventId(5);

        $history = TemporalExecutionHistory::fromEvents([
            $this->scheduled(5),
            $this->event(EventType::EVENT_TYPE_NEXUS_OPERATION_STARTED, 7, static fn(HistoryEvent $e) => $e->setNexusOperationStartedEventAttributes($started)),
        ]);

        self::assertNull($history->findNexusOperationSlotResult(0), 'A synchronous operation that has started is still in flight, not failed.');
    }

    public function testACompletionAfterAStartWithoutTokenStillWins(): void
    {
        $started = new NexusOperationStartedEventAttributes();
        $started->setScheduledEventId(5);
        $completed = new NexusOperationCompletedEventAttributes();
        $completed->setScheduledEventId(5);
        $completed->setResult((new Payload())->setData(JsonPlainPayload::encode('fini')->getData()));

        $history = TemporalExecutionHistory::fromEvents([
            $this->scheduled(5),
            $this->event(EventType::EVENT_TYPE_NEXUS_OPERATION_STARTED, 7, static fn(HistoryEvent $e) => $e->setNexusOperationStartedEventAttributes($started)),
            $this->event(EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED, 8, static fn(HistoryEvent $e) => $e->setNexusOperationCompletedEventAttributes($completed)),
        ]);

        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot);
        self::assertNull($slot['failed']);
        self::assertSame('fini', $slot['result']);
    }

    private function scheduled(int $eventId): HistoryEvent
    {
        $attrs = new NexusOperationScheduledEventAttributes();
        $attrs->setEndpoint('paiements');
        $attrs->setService('facturation');
        $attrs->setOperation('encaisser');
        // The application identity travels in the input payload, for want of a dedicated field
        // on the Temporal side — that is what the command buffer puts there.
        // The caller's payload, bare: the identity is the eventId the server assigns, and the
        // payload belongs to the user (task 1b.2).
        $attrs->setInput(JsonPlainPayload::encode(['amount' => 10]));

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
