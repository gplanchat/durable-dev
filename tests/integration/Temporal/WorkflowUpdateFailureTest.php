<?php

declare(strict_types=1);

namespace integration\Temporal;

/**
 * Tâche 3.8 — un handler d'update qui relève fait échouer **l'update**, pas l'exécution.
 *
 * C'est la différence de fond entre un update et le corps du workflow : l'update a un appelant,
 * et c'est à lui que la défaillance revient. Le workflow, lui, n'a rien vu passer et continue.
 */
final class WorkflowUpdateFailureTest extends TemporalServerTestCase
{
    public function testARaisingHandlerFailsTheUpdateAndLeavesTheWorkflowRunning(): void
    {
        $executionId = $this->startWorkflow('UpdateRefusing', []);
        $this->waitForHistoryEvent($executionId, \Temporal\Api\Enums\V1\EventType::EVENT_TYPE_WORKFLOW_TASK_COMPLETED);

        $failed = null;

        try {
            $this->workflowClient()->update($this->workflowId($executionId), 'approve', []);
        } catch (\Throwable $e) {
            $failed = $e;
        }

        self::assertNotNull($failed, "l'appelant doit recevoir la défaillance, pas un résultat vide");
        self::assertStringContainsString('approbation refusée', $failed->getMessage());

        // L'exécution n'a pas été touchée : elle répond encore, et se termine normalement.
        $this->workflowClient()->signal($this->workflowId($executionId), 'release');
        self::assertSame(
            ['completed' => true],
            $this->workflowClient()->pollForCompletion($executionId, 250, 120),
        );
    }
}
