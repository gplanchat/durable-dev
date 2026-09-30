<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Nexus;

use Gplanchat\Durable\Attribute\AsNexusServiceHandler;
use Gplanchat\Durable\Demo\Contracts\Billing\BillingContract;
use Gplanchat\Durable\Demo\Contracts\Billing\BillingServed;

/**
 * The bench serving `billing` (#669): listed in the probe's di.xml, its contract named by the
 * attribute the Symfony bundle reads. `charge` has no body here: ProbeChargeWorkflow fulfils it.
 *
 * Its answers are the bench's own, so a caller can tell them from any other server's.
 *
 * Not `final`: Magento's container instantiates it, so it may generate an `Interceptor` extending it.
 */
#[AsNexusServiceHandler(contract: BillingContract::class)]
class ProbeBillingHandler implements BillingServed
{
    public function verify(string $order, int $amount, string $currency): array
    {
        return 'EUR' === $currency
            ? ['accepted' => true, 'reason' => null]
            : ['accepted' => false, 'reason' => \sprintf('the Magento bench bills in EUR, not %s', $currency)];
    }
}
