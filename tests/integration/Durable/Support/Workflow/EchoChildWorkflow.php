<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Support\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;

#[Workflow('EchoChild')]
final class EchoChildWorkflow
{
    #[WorkflowMethod]
    public function run(int $n = 0): int
    {
        return $n * 2;
    }
}
