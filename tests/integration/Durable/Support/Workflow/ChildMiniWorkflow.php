<?php

declare(strict_types=1);

namespace integration\Durable\Support\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;

#[AsWorkflow('ChildMini')]
final class ChildMiniWorkflow
{
    #[AsWorkflowMethod]
    public function run(int $n = 0): int
    {
        return $n * 7;
    }
}
