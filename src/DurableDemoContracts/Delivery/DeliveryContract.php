<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Demo\Contracts\Delivery;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;

/**
 * What the caller sees of the `delivery` service, served by the Laravel mockup.
 *
 * The same shape as the two other contracts, and for the same reason: `ship` has no handler body, and
 * a workflow claims it with {@see \Gplanchat\Durable\Attribute\FulfilsNexusOperation}. What differs
 * here is the **host**: it is the first time the serving half of a contract is wired somewhere
 * other than the Symfony container, by `config/durable.php` and not by a compiler pass.
 *
 * ⚠ **The parameter names declared here are the interface.** The payload is keyed by name at the
 * call, and read back by name in the workflow that fulfils the operation. Both serving hosts now
 * check it at the same moment (at registration) through the same core class,
 * {@see \Gplanchat\Durable\Nexus\Serving\NexusFulfilmentParameterNames}: Symfony from its compiler
 * pass, Laravel from `config/durable.php`.
 */
#[AsNexusService('delivery')]
interface DeliveryContract extends DeliveryServed
{
    /**
     * @param string $order an order identifier
     * @param string $slot  the one {@see DeliveryServed::schedule()} returned
     *
     * @return array{shipped: bool, tracking: string}
     */
    #[AsNexusOperation('ship')]
    public function ship(string $order, string $slot): array;
}
