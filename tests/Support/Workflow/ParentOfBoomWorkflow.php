<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Support\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('ParentOfBoom')]
final class ParentOfBoomWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(): mixed
    {
        return $this->environment->executeChildWorkflow('ChildBoom', []);
    }
}
