<?php

declare(strict_types=1);

namespace integration\Durable\Support\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('ParentOfAsyncChild')]
final class ParentOfAsyncChildWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[WorkflowMethod]
    public function run(): int
    {
        // `executeChildWorkflow()` a quitté la surface : un enfant se démarre par un stub typé, et
        // l'appel du stub *assemble* — c'est `await()` qui attend (DUR038).
        return $this->environment->await(
            $this->environment->childWorkflowStub(ChildMiniWorkflow::class)->run(4),
        );
    }
}
