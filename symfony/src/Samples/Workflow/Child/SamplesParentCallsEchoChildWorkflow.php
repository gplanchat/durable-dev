<?php

declare(strict_types=1);

namespace App\Samples\Workflow\Child;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('Samples_Child_ParentCallsEcho')]
final class SamplesParentCallsEchoChildWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(string $text = 'child'): mixed
    {
        return $this->environment->childWorkflowStub(SamplesEchoChildWorkflow::class)->run($text);
    }
}
