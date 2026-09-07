<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow\Activity;

/**
 * The demonstration's implementation: three steps that do nothing but name themselves.
 *
 * It has nothing of Magento about it either, and that is deliberate — what it serves to show is
 * that the activity names come from the contract's attributes, not from strings copied into the
 * command.
 */
/*
 * Not `final`: Magento's container instantiates it, so it generates an `Interceptor` extending it.
 * Same host constraint as for the command and the factory, and the error message still does not
 * name the keyword.
 */
class DemoOrderActivities implements OrderActivities
{
    public function charge(string $orderId): string
    {
        return 'charge:' . $orderId;
    }

    public function reserveStock(string $orderId): string
    {
        return 'reserve:' . $orderId;
    }

    public function notifyCustomer(string $orderId): string
    {
        return 'notify:' . $orderId;
    }
}
