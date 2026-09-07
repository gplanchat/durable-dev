<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\DurableNexusOperationFailedException;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Failure\WorkflowFailureClassifier;
use Gplanchat\Durable\Nexus\NexusOperationFailureKind;
use PHPUnit\Framework\TestCase;

/**
 * §3.6 — a Nexus operation failure is **classified**, not flattened.
 *
 * The four kinds come from the spec, word for word: the operation failed, the handler could not
 * run, a bound elapsed, the operation was cancelled. They are not told apart for reading comfort —
 * a caller compensates on an operation failure, retries on a handler error, and does neither on a
 * cancellation it asked for itself.
 */
final class NexusOperationFailureTest extends TestCase
{
    public function testTheFourKindsAreDistinguishable(): void
    {
        self::assertCount(4, NexusOperationFailureKind::cases());
        self::assertNotSame(
            NexusOperationFailureKind::OperationFailed,
            NexusOperationFailureKind::HandlerError,
            'a handler that could not run is not an operation that failed',
        );
    }

    public function testTheFailureNamesTheCallSite(): void
    {
        // The spec requires it: "The failure SHALL carry the endpoint, service and operation
        // names so an unhandled one names the call site."
        $e = $this->failure(NexusOperationFailureKind::Timeout);

        self::assertSame('paiements', $e->endpoint());
        self::assertSame('facturation', $e->service());
        self::assertSame('encaisser', $e->operation());
        self::assertStringContainsString('paiements', $e->getMessage());
        self::assertStringContainsString('facturation', $e->getMessage());
        self::assertStringContainsString('encaisser', $e->getMessage());
    }

    public function testTheRetryBehaviourRidesOnTheHandlerErrorOnly(): void
    {
        // The spec attaches the server's retry behaviour to the handler error, and to it alone:
        // that is what tells "retrying makes sense" apart from "the handler answered no".
        $handlerError = $this->failure(NexusOperationFailureKind::HandlerError, retryBehaviour: 'retryable');
        self::assertSame('retryable', $handlerError->retryBehaviour());

        self::assertNull($this->failure(NexusOperationFailureKind::OperationFailed)->retryBehaviour());
    }

    public function testAnUnhandledFailureIsClassifiedWithItsOrigin(): void
    {
        $failed = WorkflowFailureClassifier::classify('exec-1', $this->failure(NexusOperationFailureKind::OperationFailed));

        self::assertSame(WorkflowExecutionFailed::KIND_UNHANDLED_NEXUS_OPERATION, $failed->kind());
        $context = $failed->context();
        self::assertSame('paiements', $context['endpoint'] ?? null);
        self::assertSame('facturation', $context['service'] ?? null);
        self::assertSame('encaisser', $context['operation'] ?? null);
        self::assertSame('operation_failed', $context['nexusKind'] ?? null);
    }

    public function testItIsNotFlattenedOntoTheGenericHandlerFailure(): void
    {
        // Without its branch in the classifier, the failure would fall into the catch-all and
        // the origin of the call would be lost — the failure mode §3.6 exists to prevent.
        $failed = WorkflowFailureClassifier::classify('exec-1', $this->failure(NexusOperationFailureKind::Cancellation));

        self::assertNotSame(WorkflowExecutionFailed::KIND_WORKFLOW_HANDLER, $failed->kind());
    }

    private function failure(NexusOperationFailureKind $kind, ?string $retryBehaviour = null): DurableNexusOperationFailedException
    {
        return new DurableNexusOperationFailedException(
            'paiements',
            'facturation',
            'encaisser',
            $kind,
            new FailureEnvelope(\RuntimeException::class, 'carte refusée'),
            $retryBehaviour,
        );
    }
}
