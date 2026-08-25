<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Durable\Exception\ChildWorkflowStartDeferred;
use Gplanchat\Durable\Port\ChildWorkflowRunnerInterface;

/**
 * Côté Temporal, un workflow enfant n'est jamais exécuté en ligne par le worker : le parent émet
 * COMMAND_TYPE_START_CHILD_WORKFLOW_EXECUTION et le serveur pilote la suite, jusqu'à écrire
 * CHILD_WORKFLOW_EXECUTION_COMPLETED / _FAILED dans l'historique du parent — que
 * {@see TemporalExecutionHistory} sait déjà relire.
 *
 * Sans cette implémentation, {@see \Gplanchat\Durable\ExecutionContext::executeChildWorkflow()}
 * levait une LogicException : les workflows enfants n'étaient pas utilisables sur le driver
 * Temporal, et la commande construite par {@see TemporalWorkflowCommandBuffer::scheduleChildWorkflow()}
 * n'était atteinte par aucun appelant.
 */
final class TemporalChildWorkflowRunner implements ChildWorkflowRunnerInterface
{
    public function defersChildStart(): bool
    {
        return true;
    }

    public function runChild(string $childExecutionId, string $workflowType, array $input, ?string $parentExecutionId = null): mixed
    {
        // La commande de démarrage est déjà dans le buffer ; l'awaitable reste non réglé
        // jusqu'à ce que l'historique porte l'issue de l'enfant.
        throw new ChildWorkflowStartDeferred();
    }
}
