<?php

declare(strict_types=1);

namespace App\Durable\Nexus;

use Gplanchat\Durable\Demo\Contracts\Delivery\DeliveryServed;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Logistics answers `delivery/schedule`, right away.
 *
 * It implements `DeliveryServed` and not `DeliveryContract`: a handler writes only the part of the
 * contract it answers on the task. `ship` has no method here, and that is not an omission:
 * {@see \App\Durable\Workflow\ShipWorkflow} fulfils it.
 *
 * **What differs from the two other mockups is not the code, it is the declaration.** Symfony sets a
 * tag from an attribute and a compiler pass reads the contract; here it is `config/durable.php` that
 * ties this class to its contract, because Laravel's container has no per-attribute autoconfiguration.
 * The class itself knows nothing of any of that.
 */
final readonly class DeliveryHandler implements DeliveryServed
{
    /** What logistics can carry in one round. */
    private const PARCELS_MAX = 5;

    public function __construct(
        private Cache $cache,
    ) {}

    /**
     * @param array<string, int> $lines
     *
     * @return array{scheduled: bool, slot: string, carrier: string, reason: string|null}
     */
    public function schedule(string $order, array $lines): array
    {
        // A Nexus task is redelivered: the second delivery must read back what the first decided,
        // not decide a second time; a slot drawn twice would not be the same one.
        //
        // ponytail: the cache carries the idempotence because a slot is a reproducible decision with
        // no side effect; real logistics would keep it in its shipments table, and that is where it
        // will have to go the day scheduling consumes a capacity.
        return $this->cache->rememberForever(
            'delivery:' . $order,
            fn(): array => $this->decide($lines),
        );
    }

    /**
     * @param array<string, int> $lines
     *
     * @return array{scheduled: bool, slot: string, carrier: string, reason: string|null}
     */
    private function decide(array $lines): array
    {
        $parcels = array_sum(array_map(static fn($quantity): int => (int) $quantity, $lines));

        if ($parcels <= 0) {
            return [
                'scheduled' => false,
                'slot' => '',
                'carrier' => '',
                'reason' => 'nothing to carry',
            ];
        }

        if ($parcels > self::PARCELS_MAX) {
            return [
                'scheduled' => false,
                'slot' => '',
                'carrier' => '',
                'reason' => \sprintf('%d parcels, %d at most per round', $parcels, self::PARCELS_MAX),
            ];
        }

        return [
            'scheduled' => true,
            // Tomorrow, in plain words: the demonstration is read in a terminal, and an ISO date
            // would say the same thing less legibly.
            'slot' => date('Y-m-d', strtotime('+1 day')) . ' 09:00-12:00',
            'carrier' => $parcels > 2 ? 'freight' : 'courier',
            'reason' => null,
        ];
    }
}
