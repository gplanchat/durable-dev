<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\FulfilsNexusOperation;

/** The names match those of the contract, and the extra one is optional. */
#[AsWorkflow('SettleWorkflow')]
#[FulfilsNexusOperation(DeferredBillingService::class, 'settle')]
final class SettleWorkflow
{
    #[AsWorkflowMethod]
    public function run(int $amount, string $currency, bool $dryRun = false): array
    {
        return ['settled' => $amount, 'currency' => $currency, 'dryRun' => $dryRun];
    }
}
