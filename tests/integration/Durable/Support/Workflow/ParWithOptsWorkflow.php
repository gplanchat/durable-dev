<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Support\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('ParWithOpts')]
final class ParWithOptsWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(): int
    {
        $opts = new ChildWorkflowOptions('stable-child-id', ParentClosePolicy::RequestCancel);

        return $this->environment->executeChildWorkflow('EchoChild', ['n' => 13], $opts);
    }
}
