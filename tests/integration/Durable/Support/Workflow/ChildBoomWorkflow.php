<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Support\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;

#[Workflow('ChildBoom')]
final class ChildBoomWorkflow
{
    #[WorkflowMethod]
    public function run(): never
    {
        throw new \RuntimeException('child-boom');
    }
}
