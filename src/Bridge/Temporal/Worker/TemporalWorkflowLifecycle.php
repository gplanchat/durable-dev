<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\WorkflowCancelledException;
use Gplanchat\Durable\Port\WorkflowLifecycleInterface;

/**
 * Issues de cycle de vie du backend Temporal : chacune devient une commande de la tâche courante,
 * poussée dans {@see TemporalWorkflowCommandBuffer} puis renvoyée au serveur via
 * {@code RespondWorkflowTaskCompleted}.
 *
 * Hormis l'annulation, aucune méthode ne lève : une tâche de workflow se termine en rendant ses
 * commandes, pas en remontant une exception — c'est la divergence de fond avec le backend
 * in-memory. {@see onBeforeRun()} fait exception pour empêcher le fiber de redémarrer, et
 * {@see WorkflowTaskRunner} rattrape puis renvoie quand même les commandes.
 */
final readonly class TemporalWorkflowLifecycle implements WorkflowLifecycleInterface
{
    public function __construct(
        private TemporalWorkflowCommandBuffer $commandBuffer,
        /** Cause lue dans l'historique ({@see TemporalExecutionHistory::cancellationRequestedCause()}). */
        private ?string $cancellationRequestedCause = null,
    ) {
    }

    /**
     * L'annulation Temporal est **coopérative** : le serveur ne fait qu'enregistrer
     * WORKFLOW_EXECUTION_CANCEL_REQUESTED et replanifier une tâche de workflow. C'est au worker
     * d'y répondre par COMMAND_TYPE_CANCEL_WORKFLOW_EXECUTION ; tant qu'il rejoue l'historique
     * sans rien émettre, l'exécution continue de tourner et la demande reste lettre morte.
     *
     * Même sémantique que le backend in-memory : honorée au point de reprise suivant, sans
     * exception injectée dans le fiber en cours.
     */
    public function onBeforeRun(string $executionId): void
    {
        if (null === $this->cancellationRequestedCause) {
            return;
        }

        $this->commandBuffer->cancelWorkflow($this->cancellationRequestedCause);

        throw new WorkflowCancelledException($executionId, $this->cancellationRequestedCause);
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
