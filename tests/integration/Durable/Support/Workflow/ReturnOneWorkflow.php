<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Support\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;

#[Workflow('C')]
final class ReturnOneWorkflow
{
    #[WorkflowMethod]
    public function run(): int
    {
        return 1;
    }
}
