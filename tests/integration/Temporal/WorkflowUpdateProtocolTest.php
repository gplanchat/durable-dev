<?php

declare(strict_types=1);

namespace integration\Temporal;

/**
 * Tâche 5.5 — l'acceptation et la complétion d'un update, côté worker, contre un vrai serveur.
 *
 * Ce que la sonde 1.3 a établi et que ce test exerce de bout en bout : l'update n'arrive pas par
 * l'historique mais comme message de protocole sur la tâche, et le worker l'accepte *et* y répond
 * sur cette même tâche.
 */
final class WorkflowUpdateProtocolTest extends TemporalServerTestCase
{
    public function testAnUpdateIsAnsweredAndItsValueReachesTheCaller(): void
    {
        $executionId = $this->startWorkflow('Updatable', []);
        $this->waitForHistoryEvent($executionId, \Temporal\Api\Enums\V1\EventType::EVENT_TYPE_WORKFLOW_TASK_COMPLETED);

        $answer = $this->workflowClient()->update($this->workflowId($executionId), 'approve', ['by' => 'alice']);

        self::assertSame(['approvedBy' => 'alice'], $answer, "la valeur rendue par le handler doit revenir à l'appelant");

        $names = $this->historyEventNames($executionId);
        self::assertContains('EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_ACCEPTED', $names);
        self::assertContains('EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_COMPLETED', $names);
    }
}
