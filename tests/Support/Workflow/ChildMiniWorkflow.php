<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Support\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('ChildMini')]
final class ChildMiniWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(int $n = 0): int
    {
        return $n * 7;
    }
}
