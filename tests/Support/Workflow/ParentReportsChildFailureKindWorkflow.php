<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Support\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\Exception\DurableChildWorkflowFailedException;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('ParReport')]
final class ParentReportsChildFailureKindWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(): string
    {
        try {
            return (string) $this->environment->executeChildWorkflow('Ghost', []);
        } catch (DurableChildWorkflowFailedException $e) {
            return json_encode([
                'kind' => $e->workflowFailureKind(),
                'class' => $e->workflowFailureClass(),
                'ctx' => $e->workflowFailureContext(),
            ], \JSON_THROW_ON_ERROR);
        }
    }
}
