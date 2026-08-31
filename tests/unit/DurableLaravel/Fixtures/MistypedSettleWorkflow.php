<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\FulfilsNexusOperation;

/** `$ammount` : la faute de frappe que la charge clée par nom ne peut pas rattraper. */
#[AsWorkflow('MistypedSettleWorkflow')]
#[FulfilsNexusOperation(DeferredBillingService::class, 'settle')]
final class MistypedSettleWorkflow
{
    #[AsWorkflowMethod]
    public function run(int $ammount, string $currency): array
    {
        return ['settled' => $ammount, 'currency' => $currency];
    }
}
