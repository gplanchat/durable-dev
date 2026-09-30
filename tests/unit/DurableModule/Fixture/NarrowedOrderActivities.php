<?php

declare(strict_types=1);

namespace unit\DurableModule\Fixture;

use Gplanchat\Durable\Attribute\AsActivityHandler;

/** Implements two activity contracts but names one: only the named one is served. */
#[AsActivityHandler(contract: OrderActivities::class)]
final class NarrowedOrderActivities implements OrderActivities, AuditActivities
{
    public function charge(string $orderId): string
    {
        return 'charge:' . $orderId;
    }

    public function reserveStock(string $orderId): string
    {
        return 'reserve:' . $orderId;
    }

    public function notifyCustomer(string $receipt): string
    {
        return 'notify:' . $receipt;
    }

    public function record(string $entry): string
    {
        return 'record:' . $entry;
    }
}
