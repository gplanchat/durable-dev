<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Demo\Contracts\Billing;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;

/**
 * What the caller sees of the `billing` service.
 *
 * `charge` has no handler body: a workflow claims it with
 * {@see \Gplanchat\Durable\Attribute\FulfilsNexusOperation}, and the server delivers that
 * workflow's result to the caller. It is what the contract splits for: PHP has no way of saying
 * "partially implements".
 *
 * ⚠ **The parameter names declared here are the interface, not a reading convenience.** The payload
 * is keyed by name at the call, and read back by name in the workflow that fulfils the operation. A
 * parameter renamed on one side only hands the workflow `null`, with no error and no trace.
 */
#[AsNexusService('billing')]
interface BillingContract extends BillingServed
{
    /**
     * @param int $amount in cents, as for {@see BillingServed::verify()}
     *
     * @return array{receipt: string, charged: int}
     */
    #[AsNexusOperation('charge')]
    public function charge(string $order, int $amount, string $currency): array;
}
