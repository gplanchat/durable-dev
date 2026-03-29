<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Support\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;

#[Workflow('BadChild')]
final class BadChildWorkflow
{
    #[WorkflowMethod]
    public function run(): never
    {
        throw new \RuntimeException('child boom');
    }
}
