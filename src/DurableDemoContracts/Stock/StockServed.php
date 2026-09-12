<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Demo\Contracts\Stock;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;

/**
 * What the shop already knows how to answer.
 *
 * This is the interface a handler **implements**: reserving stock is a read and a write in the
 * shop's model, not a wait. It fits in the ~9 s a Nexus task has before redelivery.
 */
#[AsNexusService('stock')]
interface StockServed
{
    /**
     * @param string             $order an order identifier, so that the reservation is idempotent:
     *                                  the same order twice does not reserve twice
     * @param array<string, int> $lines reference => quantity asked for
     *
     * @return array{reserved: bool, missing: array<string, int>} `missing` is empty when `reserved`
     *                                  is `true` — the caller therefore has one field to read to
     *                                  decide, and the second one to explain
     */
    #[AsNexusOperation('reserve')]
    public function reserve(string $order, array $lines): array;
}
