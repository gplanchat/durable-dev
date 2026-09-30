<?php

declare(strict_types=1);

namespace unit\DurableModule\Fixture;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\FulfilsNexusOperation;
use Gplanchat\Durable\Demo\Contracts\Billing\BillingContract;

/**
 * A module workflow that fulfils `billing/charge`, found in `workflowClasses` by its attribute.
 */
#[AsWorkflow(name: 'test.magento.charge')]
#[FulfilsNexusOperation(BillingContract::class, 'charge')]
final class NexusChargeWorkflow
{
    /**
     * @return array{receipt: string, charged: int}
     */
    #[AsWorkflowMethod]
    public function run(string $order, int $amount, string $currency): array
    {
        return ['receipt' => 'MAGENTO-' . $currency . '-' . $order, 'charged' => $amount];
    }
}
