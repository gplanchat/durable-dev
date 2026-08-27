<?php

declare(strict_types=1);

namespace integration\Temporal;

use Temporal\Api\Enums\V1\EventType;

/**
 * La garde de divergence, contre un vrai serveur — DUR042.
 *
 * Les tests unitaires vérifient que la comparaison refuse et que le pont répond
 * `RespondWorkflowTaskFailed`. Ils ne peuvent pas dire ce que le **serveur** fait de cette réponse,
 * et c'est là qu'était l'erreur d'origine : la conception supposait qu'une levée échouait la tâche,
 * alors qu'elle tuait l'exécution. L'hypothèse a coûté une tranche entière.
 *
 * Ce fichier tient donc la seule chose qu'aucune assertion locale ne peut tenir : après une
 * divergence, l'exécution est **toujours vivante**, et remettre le code qui a écrit l'historique la
 * fait terminer normalement.
 *
 * @see openspec/changes/workflow-replay-divergence-guard/tasks.md §3.2
 */
final class ReplayDivergenceGuardTest extends TemporalServerTestCase
{
    public function testADivergentDeployFailsTheTaskAndLeavesTheRunResumable(): void
    {
        $executionId = $this->startWorkflow('DivergentByDeploy', ['value' => 21]);

        // L'activité du slot 0 s'exécute et le workflow se suspend sur son minuteur : c'est la
        // fenêtre dans laquelle un déploiement tombe en production.
        $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_ACTIVITY_TASK_COMPLETED);
        $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_TIMER_STARTED);

        $this->redeployWorkflowWorker('divergent');

        // Le réveil du minuteur provoque le replay sur le code neuf, et la garde mord.
        $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_WORKFLOW_TASK_FAILED, 60.0);

        $noms = $this->historyEventNames($executionId);
        self::assertNotContains(
            'EVENT_TYPE_WORKFLOW_EXECUTION_FAILED',
            $noms,
            "l'exécution ne doit pas mourir : c'est le déploiement qui est fautif, et un déploiement s'annule",
        );
        self::assertNotContains(
            'EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED',
            $noms,
            'et surtout elle ne doit pas se terminer en succès avec la valeur du voisin — le défaut mesuré au départ',
        );

        // Le déploiement est annulé. Rien d'autre ne change.
        $this->redeployWorkflowWorker('default');

        $result = $this->workflowClient()->pollForCompletion($executionId, 500, 120);

        self::assertSame(
            ['variant' => 'default', 'slot0' => 42],
            $result,
            "l'exécution reprend là où elle était, sur le code qui a écrit son historique",
        );
        self::assertContains('EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED', $this->historyEventNames($executionId));
    }
}
