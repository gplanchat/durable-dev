<?php

declare(strict_types=1);

namespace integration\Durable\Support\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;

#[AsWorkflow('ChildBoom')]
final class ChildBoomWorkflow
{
    #[AsWorkflowMethod]
    public function run(): never
    {
        throw new \RuntimeException('child-boom');
    }
}
