<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\FulfilsNexusOperation;
use Gplanchat\Durable\Demo\Contracts\Billing\BillingContract;

/**
 * The bench fulfilling `billing/charge` (#669): a workflow of `workflowClasses`, found by its
 * attribute, started by the Nexus worker and run by the journal worker.
 */
#[AsWorkflow(name: 'durable.probe.charge')]
#[FulfilsNexusOperation(BillingContract::class, 'charge')]
final class ProbeChargeWorkflow
{
    /**
     * @return array{receipt: string, charged: int}
     */
    #[AsWorkflowMethod]
    public function run(string $order, int $amount, string $currency): array
    {
        return ['receipt' => 'MAGENTO-BENCH-' . $currency . '-' . $order, 'charged' => $amount];
    }
}
