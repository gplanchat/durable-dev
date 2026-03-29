<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Bundle\Support;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('OrderWait')]
final class OrderWaitWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[WorkflowMethod]
    public function run(): array
    {
        return $this->environment->waitSignal('approved');
    }
}
