<?php

declare(strict_types=1);

namespace unit\DurableModule\Fixture;

use Gplanchat\Durable\Attribute\AsNexusServiceHandler;
use Gplanchat\Durable\Demo\Contracts\Billing\BillingContract;
use Gplanchat\Durable\Demo\Contracts\Billing\BillingServed;

/**
 * A Magento module's Nexus handler: listed in di.xml, its contract named by the attribute, as on
 * Symfony. `charge` has no body here — {@see NexusChargeWorkflow} fulfils it.
 */
#[AsNexusServiceHandler(contract: BillingContract::class)]
final class NexusBillingHandler implements BillingServed
{
    public function verify(string $order, int $amount, string $currency): array
    {
        return ['accepted' => 'EUR' === $currency, 'reason' => 'EUR' === $currency ? null : 'EUR only, on this module'];
    }
}
