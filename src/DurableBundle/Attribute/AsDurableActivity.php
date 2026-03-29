<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Attribute;

/**
 * Marque une classe comme implémentation des activités d'un contrat (interface + #[ActivityMethod]).
 * Le bundle enregistre chaque activité sur {@see \Gplanchat\Durable\ActivityExecutor} au compile-time.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsDurableActivity
{
    /**
     * @param class-string $contract
     */
    public function __construct(
        public string $contract,
    ) {
    }
}
