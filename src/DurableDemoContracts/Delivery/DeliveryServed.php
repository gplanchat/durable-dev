<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Demo\Contracts\Delivery;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;

/**
 * What logistics already knows how to answer.
 *
 * Picking a slot and a carrier is a computation over data one already holds: it fits in the ~9 s of
 * a Nexus task. Getting the goods out of the door does not — that is
 * {@see DeliveryContract::ship()}, and a workflow is what fulfils it.
 */
#[AsNexusService('delivery')]
interface DeliveryServed
{
    /**
     * @param string             $order an order identifier, so that scheduling the same order twice
     *                                  returns the same slot
     * @param array<string, int> $lines reference => quantity, what there is to carry
     *
     * @return array{scheduled: bool, slot: string, carrier: string, reason: string|null}
     *                                  `reason` only means anything when `scheduled` is `false`
     */
    #[AsNexusOperation('schedule')]
    public function schedule(string $order, array $lines): array;
}
