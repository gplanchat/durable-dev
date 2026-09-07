<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Demo\Contracts\Billing;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;

/**
 * What the business already knows how to answer.
 *
 * Verifying is applying rules to data one already holds. Charging is not, hence the split into two
 * interfaces.
 */
#[AsNexusService('billing')]
interface BillingServed
{
    /**
     * @param int    $amount   in cents, because a float that crosses a JSON encoding and then a
     *                         decoding is no longer quite the same number
     * @param string $currency an ISO 4217 code
     *
     * @return array{accepted: bool, reason: string|null} `reason` is `null` when `accepted` is `true`
     */
    #[AsNexusOperation('verify')]
    public function verify(string $order, int $amount, string $currency): array;
}
