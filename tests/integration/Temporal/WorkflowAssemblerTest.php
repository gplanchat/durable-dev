<?php

declare(strict_types=1);

namespace integration\Temporal;

use Temporal\Api\Enums\V1\EventType;

/**
 * The assemblers against a real server (ADR DUR033).
 *
 * What the unit tests cannot see: they play the synchronous drain, where there is neither a
 * suspended fiber nor a workflow task. Yet `all()` went from N suspensions down to a single one —
 * the command sequence does not change, the task boundaries do, and it is the server that cuts
 * them.
 */
final class WorkflowAssemblerTest extends TemporalServerTestCase
{
    public function testAnAssemblySchedulesEveryBranchInOneWorkflowTask(): void
    {
        $executionId = $this->startWorkflow('Assembled', ['value' => 21]);
        self::assertSame(
            ['both' => [42, 'x!']],
            $this->workflowClient()->pollForCompletion($executionId, 250, 120),
        );

        $names = $this->historyEventNames($executionId);
        $scheduled = array_keys($names, EventType::name(EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED), true);
        self::assertCount(2, $scheduled, 'both branches must be scheduled');

        // A workflow task closes on WORKFLOW_TASK_COMPLETED, followed by the commands it has
        // produced. Such an event between the two schedulings would mean two engine turns.
        $between = \array_slice($names, $scheduled[0], $scheduled[1] - $scheduled[0]);
        self::assertNotContains(
            EventType::name(EventType::EVENT_TYPE_WORKFLOW_TASK_COMPLETED),
            $between,
            "the two branches were scheduled in two engine turns: \n" . implode("\n", $names),
        );
    }

    public function testAQuorumCompletesAndTheServerCancelsTheLosingBranches(): void
    {
        // The losers are one- and two-hour timers: without effective cancellation on the server
        // side, the execution would not finish.
        $executionId = $this->startWorkflow('Quorum', []);
        self::assertSame(
            ['keys' => [0, 1], 'values' => [2, 4]],
            $this->workflowClient()->pollForCompletion($executionId, 250, 120),
        );

        $names = $this->historyEventNames($executionId);
        self::assertCount(
            2,
            array_keys($names, EventType::name(EventType::EVENT_TYPE_TIMER_STARTED), true),
            'both losing timers must have been started',
        );
        self::assertCount(
            2,
            array_keys($names, EventType::name(EventType::EVENT_TYPE_TIMER_CANCELED), true),
            'and removed once the quorum is reached',
        );
    }
}
