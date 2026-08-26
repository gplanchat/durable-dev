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
 * §3.6 — un échec d'opération Nexus est **classé**, pas aplati.
 *
 * Les quatre natures viennent du spec, mot pour mot : l'opération a échoué, le handler n'a pas pu
 * tourner, une borne s'est écoulée, l'opération a été annulée. Elles ne se distinguent pas par
 * confort de lecture — un appelant compense sur un échec d'opération, réessaie sur une erreur de
 * handler, et ne fait ni l'un ni l'autre sur une annulation qu'il a lui-même demandée.
 */
final class NexusOperationFailureTest extends TestCase
{
    public function testTheFourKindsAreDistinguishable(): void
    {
        self::assertCount(4, NexusOperationFailureKind::cases());
        self::assertNotSame(
            NexusOperationFailureKind::OperationFailed,
            NexusOperationFailureKind::HandlerError,
            "un handler qui n'a pas pu tourner n'est pas une opération qui a échoué",
        );
    }

    public function testTheFailureNamesTheCallSite(): void
    {
        // Le spec l'exige : « The failure SHALL carry the endpoint, service and operation names so
        // an unhandled one names the call site. »
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
        // Le spec attache le comportement de reprise du serveur à l'erreur de handler, et à elle
        // seule : c'est ce qui distingue « réessayer a un sens » de « le handler a répondu non ».
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
        // Sans sa branche dans le classificateur, l'échec tomberait dans le fourre-tout et
        // l'origine de l'appel serait perdue — c'est la panne que §3.6 existe pour empêcher.
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
