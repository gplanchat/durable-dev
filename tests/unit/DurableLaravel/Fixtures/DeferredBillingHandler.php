<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

/** It serves nothing but `charge`: `settle` belongs to a workflow. */
final class DeferredBillingHandler
{
    /** @return array<string, mixed> */
    public function charge(int $amount): array
    {
        return ['charged' => $amount];
    }
}
