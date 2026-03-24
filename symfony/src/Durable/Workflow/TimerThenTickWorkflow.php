<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use App\Durable\Activity\TickActivity;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('TimerThenTickWorkflow')]
final class TimerThenTickWorkflow
{
    private readonly ActivityStub $tick;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->tick = $environment->activityStub(TickActivity::class);
    }

    #[WorkflowMethod]
    public function run(float $seconds = 0.01): string
    {
        $this->environment->timer($seconds);

        return $this->environment->await($this->tick->tick());
    }
}
