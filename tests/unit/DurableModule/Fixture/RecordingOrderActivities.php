<?php

declare(strict_types=1);

namespace unit\DurableModule\Fixture;

final class RecordingOrderActivities implements OrderActivities
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
}
