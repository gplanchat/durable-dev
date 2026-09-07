<?php

declare(strict_types=1);

namespace integration\Durable\Support\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow('ParentOfAsyncChild')]
final class ParentOfAsyncChildWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(): int
    {
        // `executeChildWorkflow()` has left the surface: a child is started through a typed stub,
        // and the stub call *assembles* — it is `await()` that waits (DUR038).
        return $this->environment->await(
            $this->environment->childWorkflowStub(ChildMiniWorkflow::class)->run(4),
        );
    }
}
