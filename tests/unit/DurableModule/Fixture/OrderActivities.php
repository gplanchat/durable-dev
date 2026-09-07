<?php

declare(strict_types=1);

namespace unit\DurableModule\Fixture;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * The contract the module's tests employ.
 *
 * It lives **here** and not in the package: what these tests put to the test is the declaration
 * mechanism, not one workflow in particular. A test contract inside the published package would be
 * weight that every consuming project would carry for nothing.
 */
interface OrderActivities
{
    #[AsActivityMethod(name: 'test.order.charge')]
    public function charge(string $orderId): string;

    #[AsActivityMethod(name: 'test.order.reserve')]
    public function reserveStock(string $orderId): string;

    #[AsActivityMethod(name: 'test.order.notify')]
    public function notifyCustomer(string $receipt): string;
}
