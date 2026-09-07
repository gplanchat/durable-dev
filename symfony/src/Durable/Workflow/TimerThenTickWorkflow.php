<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use App\Durable\Activity\TickActivityInterface;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow('TimerThenTickWorkflow')]
final class TimerThenTickWorkflow
{
    private readonly ActivityStub $tick;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->tick = $environment->activityStub(TickActivityInterface::class);
    }

    #[AsWorkflowMethod]
    public function run(float $seconds = 0.01): string
    {
        // `sleep()` and not `timer()`: the second one returns an awaitable. Calling it without
        // awaiting starts a timer nobody watches, and the workflow carries on — the history then
        // bears a `TimerStarted` with no `TimerFired`. This workflow's name promises the opposite.
        $this->environment->sleep($seconds);

        return $this->environment->await($this->tick->tick());
    }
}
