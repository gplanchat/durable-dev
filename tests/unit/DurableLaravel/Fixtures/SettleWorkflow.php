<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\FulfilsNexusOperation;

/** Les noms coïncident avec ceux du contrat, et l'extra est facultatif. */
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
