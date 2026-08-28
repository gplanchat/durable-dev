<?php

declare(strict_types=1);

namespace App\Samples\Workflow\Child;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow('Samples_Child_ParentCallsEcho')]
final class SamplesParentCallsEchoChildWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[AsWorkflowMethod]
    public function run(string $text = 'child'): mixed
    {
        return $this->environment->await(
            $this->environment->childWorkflowStub(SamplesEchoChildWorkflow::class)->run($text),
        );
    }
}
