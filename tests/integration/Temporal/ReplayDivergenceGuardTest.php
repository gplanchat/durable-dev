<?php

declare(strict_types=1);

namespace integration\Temporal;

use Temporal\Api\Enums\V1\EventType;

/**
 * The divergence guard, against a real server — DUR042.
 *
 * The unit tests check that the comparison refuses and that the bridge answers
 * `RespondWorkflowTaskFailed`. They cannot say what the **server** does with that answer, and that
 * is where the original mistake lay: the design assumed that a raise failed the task, when it in
 * fact killed the execution. The assumption cost a whole slice.
 *
 * So this file holds the one thing no local assertion can hold: after a divergence, the execution
 * is **still alive**, and putting back the code that wrote the history makes it finish normally.
 *
 * @see openspec/changes/workflow-replay-divergence-guard/tasks.md §3.2
 */
final class ReplayDivergenceGuardTest extends TemporalServerTestCase
{
    public function testADivergentDeployFailsTheTaskAndLeavesTheRunResumable(): void
    {
        $executionId = $this->startWorkflow('DivergentByDeploy', ['value' => 21]);

        // The slot 0 activity runs and the workflow suspends on its timer: that is the window in
        // which a deployment lands in production.
        $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_ACTIVITY_TASK_COMPLETED);
        $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_TIMER_STARTED);

        $this->redeployWorkflowWorker('divergent');

        // The timer waking up triggers the replay on the new code, and the guard bites.
        $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_WORKFLOW_TASK_FAILED, 60.0);

        $noms = $this->historyEventNames($executionId);
        self::assertNotContains(
            'EVENT_TYPE_WORKFLOW_EXECUTION_FAILED',
            $noms,
            'the execution must not die: it is the deployment that is at fault, and a deployment can be rolled back',
        );
        self::assertNotContains(
            'EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED',
            $noms,
            'and above all it must not complete successfully with the value of its neighbour — the defect measured at the start',
        );

        // The deployment is rolled back. Nothing else changes.
        $this->redeployWorkflowWorker('default');

        $result = $this->workflowClient()->pollForCompletion($executionId, 500, 120);

        self::assertSame(
            ['variant' => 'default', 'slot0' => 42],
            $result,
            'the execution resumes where it was, on the code that wrote its history',
        );
        self::assertContains('EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED', $this->historyEventNames($executionId));
    }
}
