<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow('ParentCallsEchoChildWorkflow')]
final class ParentCallsEchoChildWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[AsWorkflowMethod]
    public function run(string $text = 'child'): mixed
    {
        return $this->environment->await(
            $this->environment->childWorkflowStub(EchoChildWorkflow::class)->run($text),
        );
    }
}
