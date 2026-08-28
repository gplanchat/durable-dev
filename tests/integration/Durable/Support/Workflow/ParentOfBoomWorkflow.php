<?php

declare(strict_types=1);

namespace integration\Durable\Support\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow('ParentOfBoom')]
final class ParentOfBoomWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(): mixed
    {
        return $this->environment->await(
            $this->environment->childWorkflowStub(ChildBoomWorkflow::class)->run(),
        );
    }
}
