<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Support\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;

#[Workflow('Child')]
final class ChildWorkflow
{
    #[WorkflowMethod]
    public function run(int $v = 0): int
    {
        return $v * 10;
    }
}
