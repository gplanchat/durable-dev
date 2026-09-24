<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Waits for a signal with no deadline: a run in this state appends nothing to its journal, so only
 * the worker that took it can say it was picked up.
 */
#[AsWorkflow('AwaitApproval')]
final class AwaitApprovalWorkflow
{
    private bool $approved = false;

    #[AsSignalMethod('approve')]
    public function approve(array $payload): void
    {
        $this->approved = true;
    }

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): string
    {
        $env->await(fn(): bool => $this->approved);

        return 'approved';
    }
}
