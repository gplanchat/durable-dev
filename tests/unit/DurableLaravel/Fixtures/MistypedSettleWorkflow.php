<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\FulfilsNexusOperation;

/** `$ammount`: the typo that a payload keyed by name cannot catch. */
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
