<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Port\WorkflowLifecycleInterface;

/**
 * Issues de cycle de vie du backend Temporal : chacune devient une commande de la tâche courante,
 * poussée dans {@see TemporalWorkflowCommandBuffer} puis renvoyée au serveur via
 * {@code RespondWorkflowTaskCompleted}.
 *
 * Aucune méthode ne lève : une tâche de workflow se termine en rendant ses commandes, pas en
 * remontant une exception — c'est la seule divergence de fond avec le backend in-memory.
 */
final readonly class TemporalWorkflowLifecycle implements WorkflowLifecycleInterface
{
    public function __construct(
        private TemporalWorkflowCommandBuffer $commandBuffer,
    ) {
    }

    public function onBeforeRun(string $executionId): void
    {
        // L'annulation appartient au serveur Temporal : rien à honorer côté worker.
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
