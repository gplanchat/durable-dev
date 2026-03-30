<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('ParentCallsEchoChildWorkflow')]
final class ParentCallsEchoChildWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(string $text = 'child'): mixed
    {
        return $this->environment->childWorkflowStub(EchoChildWorkflow::class)->run($text);
    }
}
