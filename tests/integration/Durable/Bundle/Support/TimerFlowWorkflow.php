<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Bundle\Support;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('TimerFlow')]
final class TimerFlowWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(): string
    {
        $this->environment->timer(42.0);

        return 'after-timer';
    }
}
