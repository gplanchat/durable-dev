<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * The demonstration's contract: the failure the integration exists to remove,
 * reduced to three steps — charge, reserve, notify.
 *
 * It is OST003's example word for word: "a consumer that dies half way through
 * an order". The order is charged, the stock is not.
 */
interface OrderActivities
{
    #[AsActivityMethod(name: 'durable.demo.charge')]
    public function charge(string $orderId): string;

    #[AsActivityMethod(name: 'durable.demo.reserve')]
    public function reserveStock(string $orderId): string;

    #[AsActivityMethod(name: 'durable.demo.notify')]
    public function notifyCustomer(string $orderId): string;
}
