<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Bundle\Support;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('ActFlow')]
final class ActFlowWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(): string
    {
        return $this->environment->await($this->environment->activity('echo', ['v' => 'queued-ok']));
    }
}
