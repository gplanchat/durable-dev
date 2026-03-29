<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Support\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowIdReusePolicy;

#[Workflow('P')]
final class ParentCollidingChildWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(): int
    {
        return $this->environment->executeChildWorkflow('C', [], new ChildWorkflowOptions(
            workflowId: 'colliding-child',
            workflowIdReusePolicy: WorkflowIdReusePolicy::RejectDuplicate,
        ));
    }
}
