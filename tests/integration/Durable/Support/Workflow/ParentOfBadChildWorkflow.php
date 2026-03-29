<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Support\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\Exception\DurableChildWorkflowFailedException;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('Par')]
final class ParentOfBadChildWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(): string
    {
        try {
            return $this->environment->executeChildWorkflow('BadChild', []);
        } catch (DurableChildWorkflowFailedException) {
            return 'caught';
        }
    }
}
