<?php

declare(strict_types=1);

namespace integration\DurableModule\ClientPort\Fixture;

use Gplanchat\Durable\Attribute\AsQueryMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/** Waits for the `pay` signal and ends with its tag: no timer, so a result arrives as soon as a worker has run the signal. */
#[AsWorkflow(PaidOrderWorkflow::TYPE)]
final class PaidOrderWorkflow
{
    public const TYPE = 'integration.client.paid-order';

    private string $status = 'waiting';

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env, string $tag = ''): string
    {
        $env->await(fn(): bool => 'paid' === $this->status, label: 'signal pay');

        return 'paid:' . $tag;
    }

    #[AsSignalMethod('pay')]
    public function pay(): void
    {
        $this->status = 'paid';
    }

    #[AsQueryMethod('status')]
    public function status(): string
    {
        return $this->status;
    }
}
