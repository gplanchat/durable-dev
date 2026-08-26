<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\ActivityCancellationReason;
use Gplanchat\Durable\Awaitable\AnyAwaitable;
use Gplanchat\Durable\Awaitable\AwaitableCancellation;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Failure\WorkflowFailureClassifier;
use Gplanchat\Durable\Nexus\DurableNexusOperationFailedException;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Port\WorkflowHistorySourceInterface;
use PHPUnit\Framework\TestCase;

/**
 * L'échec typé d'une opération Nexus et son annulation — §3.6 et §3.5.
 *
 * Les quatre issues ne sont pas inventées : ce sont les quatre événements terminaux que le
 * protobuf du serveur déclare — `COMPLETED`, `FAILED`, `CANCELED`, `TIMED_OUT` — et il porte deux
 * infos d'échec distinctes, `NexusOperationFailureInfo` et `NexusHandlerFailureInfo`. Un workflow
 * qui compense doit pouvoir dire « l'opération a échoué » de « celui qui la sert a échoué ».
 */
final class NexusFailureAndCancellationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // §3.6 — l'échec dit d'où il vient
    // -------------------------------------------------------------------------

    public function testEachOriginIsDistinguishable(): void
    {
        $failed = DurableNexusOperationFailedException::operationFailed('op-1', 'charge', 'declined');
        $handler = DurableNexusOperationFailedException::handlerFailed('op-1', 'charge', 'endpoint exploded');
        $timedOut = DurableNexusOperationFailedException::timedOut('op-1', 'charge');
        $cancelled = DurableNexusOperationFailedException::cancelled('op-1', 'charge', 'workflow_cancelled');

        self::assertSame(
            ['operation_failed', 'handler_failed', 'timed_out', 'cancelled'],
            [$failed->kind(), $handler->kind(), $timedOut->kind(), $cancelled->kind()],
        );

        // Le nom de l'opération est dans le message : sans lui, un workflow qui en appelle
        // plusieurs ne sait pas laquelle a lâché.
        foreach ([$failed, $handler, $timedOut, $cancelled] as $failure) {
            self::assertStringContainsString('charge', $failure->getMessage());
            self::assertSame('op-1', $failure->operationId());
        }
    }

    public function testTheOriginSurvivesInTheClassifiedFailure(): void
    {
        // Aplatie en « échec de handler de workflow », l'origine serait perdue au moment où elle
        // sert le plus : à la relecture d'un journal d'exécution ratée.
        $event = WorkflowFailureClassifier::classify(
            'exec-1',
            DurableNexusOperationFailedException::handlerFailed('op-1', 'charge', 'endpoint exploded'),
        );

        self::assertSame(WorkflowExecutionFailed::KIND_NEXUS_OPERATION, $event->kind());
        self::assertStringContainsString('charge', $event->failureMessage());
    }

    // -------------------------------------------------------------------------
    // §3.5 — l'annulation du workflow retire l'opération en vol
    // -------------------------------------------------------------------------

    public function testCancellingTheWorkflowCancelsAPendingOperation(): void
    {
        $buffer = $this->createMock(WorkflowCommandBufferInterface::class);
        $buffer->expects(self::once())
            ->method('cancelNexusOperation')
            ->with('op-en-vol', ActivityCancellationReason::WORKFLOW_CANCELLED);

        $context = $this->contextWithPendingOperation($buffer);
        $pending = $context->nexusOperation(
            NexusEndpoint::named('payments'),
            NexusService::named('billing.v1.Billing'),
            NexusOperationName::named('charge'),
        );

        $cancelled = AwaitableCancellation::cancelUnsettled($context, $pending, ActivityCancellationReason::WORKFLOW_CANCELLED);

        self::assertSame(['op-en-vol'], $cancelled);
    }

    public function testAnOperationBuriedInACompositeIsCancelledToo(): void
    {
        // Le composite doit être traversé : une opération sous un `any()` borné par une échéance
        // resterait sinon en vol après l'annulation du workflow.
        $buffer = $this->createMock(WorkflowCommandBufferInterface::class);
        $buffer->expects(self::once())->method('cancelNexusOperation');

        $context = $this->contextWithPendingOperation($buffer);
        $pending = $context->nexusOperation(
            NexusEndpoint::named('payments'),
            NexusService::named('billing.v1.Billing'),
            NexusOperationName::named('charge'),
        );

        $cancelled = AwaitableCancellation::cancelUnsettled(
            $context,
            new AnyAwaitable([$pending]),
            ActivityCancellationReason::WORKFLOW_CANCELLED,
        );

        self::assertSame(['op-en-vol'], $cancelled);
    }

    public function testAnAlreadySettledOperationIsLeftAlone(): void
    {
        $buffer = $this->createMock(WorkflowCommandBufferInterface::class);
        $buffer->expects(self::never())->method('cancelNexusOperation');

        $history = $this->createStub(WorkflowHistorySourceInterface::class);
        $history->method('findScheduledNexusOperation')->willReturn('op-finie');
        $history->method('findNexusOperationSlotResult')->willReturn(['result' => 'ok', 'failed' => null]);

        $context = new ExecutionContext('nexus-2', $history, $buffer);
        $settled = $context->nexusOperation(
            NexusEndpoint::named('payments'),
            NexusService::named('billing.v1.Billing'),
            NexusOperationName::named('charge'),
        );

        self::assertSame([], AwaitableCancellation::cancelUnsettled($context, $settled, ActivityCancellationReason::WORKFLOW_CANCELLED));
    }

    // -------------------------------------------------------------------------

    private function contextWithPendingOperation(WorkflowCommandBufferInterface $buffer): ExecutionContext
    {
        $history = $this->createStub(WorkflowHistorySourceInterface::class);
        $history->method('findScheduledNexusOperation')->willReturn('op-en-vol');
        $history->method('findNexusOperationSlotResult')->willReturn(null);

        return new ExecutionContext('nexus-2', $history, $buffer);
    }
}
