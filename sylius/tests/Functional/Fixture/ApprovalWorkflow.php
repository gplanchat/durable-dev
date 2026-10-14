<?php

declare(strict_types=1);

namespace App\Tests\Functional\Fixture;

use Gplanchat\Durable\Attribute\AsQueryMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Waits for the `approve` signal, then returns the document it was started with. The `status`
 * query answers `pending`, then `approved`. Registered in the `test` environment only, by
 * config/packages/test/durable.yaml, and driven through {@see ApprovalRepository}.
 */
#[AsWorkflow(self::TYPE)]
final class ApprovalWorkflow
{
    public const TYPE = 'bench-approval';

    private bool $approved = false;

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env, string $document): string
    {
        $env->await(fn(): bool => $this->approved, label: 'signal approve');

        return $document . ' approved';
    }

    #[AsSignalMethod('approve')]
    public function approve(): void
    {
        $this->approved = true;
    }

    #[AsQueryMethod('status')]
    public function status(): string
    {
        return $this->approved ? 'approved' : 'pending';
    }
}
