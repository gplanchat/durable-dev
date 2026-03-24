<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Support\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('ParentOfAsyncChild')]
final class ParentOfAsyncChildWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(): int
    {
        return $this->environment->executeChildWorkflow('ChildMini', ['n' => 4]);
    }
}
