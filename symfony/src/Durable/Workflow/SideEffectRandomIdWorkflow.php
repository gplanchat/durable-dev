<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('SideEffectRandomIdWorkflow')]
final class SideEffectRandomIdWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(): string
    {
        return $this->environment->sideEffect(static fn (): string => bin2hex(random_bytes(4)));
    }
}
