<?php

declare(strict_types=1);

namespace Gplanchat\DurableSqlSpike\Workflow;

use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\WorkflowEnvironment;

/** The #709 key experiment: an activity, a timer, a signal, then a second activity. */
#[AsWorkflow('SpikeOrder')]
final class SpikeOrderWorkflow
{
    private ?string $approvedBy = null;

    /** @param array<string, mixed> $payload */
    #[AsSignalMethod('approve')]
    public function approve(array $payload): void
    {
        $this->approvedBy = (string) ($payload['by'] ?? 'unknown');
    }

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env, string $orderId, int $pauseSeconds = 0, int $timerSeconds = 5): array
    {
        $activities = $env->activityStub(SpikeActivities::class);
        $receipt = $env->await($activities->charge($orderId, $pauseSeconds));
        $env->sleep(Duration::seconds($timerSeconds));
        $env->await(fn(): bool => null !== $this->approvedBy);

        return ['receipt' => $receipt, 'shipment' => $env->await($activities->ship($orderId, (string) $this->approvedBy))];
    }
}
