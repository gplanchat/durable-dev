<?php

declare(strict_types=1);

namespace integration\Temporal;

use Temporal\Api\Enums\V1\EventType;

/**
 * L'échéance côté workflow, contre un vrai serveur.
 *
 * Le cas qui ne se délègue pas à un faux : un signal livré *après* le tir de l'échéance. Chaque
 * tâche de workflow rejoue l'exécution depuis le début, l'historique contient alors le minuteur
 * tiré *et* le signal, et rien d'autre que leur ordre ne dit lequel a réglé l'attente.
 */
final class WorkflowDeadlineTest extends TemporalServerTestCase
{
    public function testASignalDeliveredAfterItsDeadlineDoesNotUndoTheTimeout(): void
    {
        $executionId = $this->startWorkflow('SignalDeadline', []);

        // L'échéance a tiré : ce qui suit est enregistré après elle.
        $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_TIMER_FIRED);
        $this->workflowClient()->signal($this->workflowId($executionId), 'approve', ['by' => 'late']);

        $result = $this->workflowClient()->pollForCompletion($executionId, 250, 160);

        self::assertSame(['timeout'], $result['first'] ?? null, 'le signal en retard ne défait pas l’échéance');
        self::assertSame(['signal', ['by' => 'late']], $result['second'] ?? null, 'il reste disponible pour l’attente suivante');
    }
}
