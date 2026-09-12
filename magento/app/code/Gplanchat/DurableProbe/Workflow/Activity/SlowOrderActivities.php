<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * The §5.3 contract: charge, reserve, notify — the failure OST003 names, reduced to three steps,
 * one of which drags on long enough for the process to be killed half way through.
 *
 * `charge` leaves an **observable** trace: it is the one that says whether the card was charged
 * twice. With no side effect, "it does not charge again" is not measured, it is believed.
 */
interface SlowOrderActivities
{
    #[AsActivityMethod(name: 'durable.probe.charge')]
    public function charge(string $orderId): string;

    #[AsActivityMethod(name: 'durable.probe.reserve')]
    public function reserveStock(string $orderId, int $pauseSeconds): string;

    #[AsActivityMethod(name: 'durable.probe.notify')]
    public function notifyCustomer(string $receipt): string;
}
