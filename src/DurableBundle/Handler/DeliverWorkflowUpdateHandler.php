<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Handler;

use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;

/**
 * Remet l'update à la prochaine passe du workflow, qui le traitera et consignera son issue.
 *
 * Ce handler n'écrit plus rien lui-même. Il ne le pouvait pas honnêtement : l'issue d'un update
 * est le retour de son handler, que seule une passe du workflow produit — c'est ce que fait le
 * worker Temporal, qui accepte *et* répond sur la même tâche. Écrire ici un résultat fourni par
 * l'appelant était l'inverse du modèle.
 *
 * La passe est celle de {@see ResumeWorkflowHandler} : rien du cycle de vie d'une exécution —
 * suspension, continue-as-new, clôture, remontée au parent — n'est réécrit ici.
 */
final class DeliverWorkflowUpdateHandler
{
    public function __construct(
        private readonly WorkflowResumeDispatcher $resumeDispatcher,
    ) {}

    public function __invoke(DeliverWorkflowUpdateMessage $message): void
    {
        $this->resumeDispatcher->dispatchResume($message->executionId, [[
            'name' => $message->updateName,
            'arguments' => $message->arguments,
        ]]);
    }
}
