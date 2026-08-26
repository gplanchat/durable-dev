<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Port\WorkflowLifecycleInterface;

/**
 * Issues de cycle de vie du backend Temporal : chacune devient une commande de la tâche courante,
 * poussée dans {@see TemporalWorkflowCommandBuffer} puis renvoyée au serveur via
 * {@code RespondWorkflowTaskCompleted}.
 *
 * Aucune méthode ne lève : une tâche de workflow se termine en rendant ses commandes, pas en
 * remontant une exception — c'est la divergence de fond avec le backend in-memory.
 */
final readonly class TemporalWorkflowLifecycle implements WorkflowLifecycleInterface
{
    public function __construct(
        private TemporalWorkflowCommandBuffer $commandBuffer,
        /** Cause lue dans l'historique ({@see TemporalExecutionHistory::cancellationRequestedCause()}). */
        private ?string $cancellationRequestedCause = null,
        /** Une tâche antérieure a déjà relevé l'annulation dans le fiber. */
        private bool $cancellationAlreadyDelivered = false,
    ) {}

    public function onBeforeRun(string $executionId): void
    {
        // Rien à pré-empter : l'annulation est livrée dans le fiber, au point d'attente.
    }

    /**
     * L'annulation Temporal est **coopérative** : le serveur ne fait qu'enregistrer
     * WORKFLOW_EXECUTION_CANCEL_REQUESTED et replanifier une tâche de workflow. C'est au worker
     * d'y répondre — ici en relevant un {@see WorkflowCancelledFailure} dans le fiber, puis par
     * COMMAND_TYPE_CANCEL_WORKFLOW_EXECUTION si le handler ne l'avale pas.
     *
     * L'historique Temporal ne peut pas porter la *raison* d'une annulation d'opération : la trace
     * de livraison passe donc par un marqueur, que {@see TemporalExecutionHistory} relit pour
     * rejeter les mêmes opérations avec la même exception au rejeu.
     */
    public function isCancellationPending(string $executionId): bool
    {
        return null !== $this->cancellationRequestedCause && !$this->cancellationAlreadyDelivered;
    }

    public function onCancellationDelivered(string $executionId, array $cancelledOperationIds): void
    {
        $this->commandBuffer->recordCancellationDelivered($cancelledOperationIds);
    }

    public function onCancelled(string $executionId, WorkflowCancelledFailure $failure): void
    {
        $this->commandBuffer->cancelWorkflow($this->cancellationRequestedCause ?? $failure->reason);
    }

    public function onCompleted(string $executionId, mixed $result): void
    {
        $this->commandBuffer->completeWorkflow($result);
    }

    public function onSuspended(string $executionId, Awaitable $pending): void
    {
        // La commande est déjà dans le buffer ; la tâche se termine en la renvoyant.
    }

    public function onContinuedAsNew(string $executionId, ContinueAsNewRequested $request): void
    {
        $this->commandBuffer->continueAsNew($request->workflowType, $request->payload, $request->options);
    }

    public function onFailed(string $executionId, \Throwable $failure): void
    {
        $this->commandBuffer->failWorkflow($failure);
    }
}
