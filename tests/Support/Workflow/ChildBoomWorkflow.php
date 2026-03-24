<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Support\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('ChildBoom')]
final class ChildBoomWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(): never
    {
        throw new \RuntimeException('child-boom');
    }
}
