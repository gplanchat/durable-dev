<?php

declare(strict_types=1);

namespace App\Durable\Nexus;

use Gplanchat\Durable\Attribute\AsNexusServiceHandler;
use Gplanchat\Durable\Demo\Contracts\Billing\BillingContract;
use Gplanchat\Durable\Demo\Contracts\Billing\BillingServed;

/**
 * The business serves `billing`. It writes only one half of it.
 *
 * The attribute names `BillingContract` — the **whole** contract, the one the caller reads — while
 * the class implements only `BillingServed`. That is not an inconsistency: it is what lets the
 * compiler pass check the coverage operation by operation, and find that `charge` has no body here
 * because a workflow claims it.
 *
 * Without that split into two interfaces, PHP would demand an empty `charge()` method here, whose
 * only role would be to say there is nothing to write.
 */
#[AsNexusServiceHandler(contract: BillingContract::class)]
final readonly class BillingHandler implements BillingServed
{
    /**
     * Verifying fits in the budget of one task: it is rules over data one already holds.
     *
     * @return array{accepted: bool, reason: string|null}
     */
    public function verify(string $order, int $amount, string $currency): array
    {
        if ('EUR' !== strtoupper($currency)) {
            return ['accepted' => false, 'reason' => \sprintf('currency %s is not supported', $currency)];
        }

        if ($amount <= 0) {
            return ['accepted' => false, 'reason' => 'amount is zero or negative'];
        }

        if ($amount > 500_000) {
            return ['accepted' => false, 'reason' => 'above the 5,000.00 EUR ceiling'];
        }

        return ['accepted' => true, 'reason' => null];
    }
}
