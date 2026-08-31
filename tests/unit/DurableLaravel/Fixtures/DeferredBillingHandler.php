<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

/** Il ne sert que `charge` : `settle` appartient à un workflow. */
final class DeferredBillingHandler
{
    public function charge(int $amount): array
    {
        return ['charged' => $amount];
    }
}
