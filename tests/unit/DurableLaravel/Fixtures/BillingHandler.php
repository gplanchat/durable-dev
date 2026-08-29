<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Gplanchat\Durable\Attribute\AsNexusServiceHandler;

#[AsNexusServiceHandler(BillingService::class)]
final class BillingHandler
{
    public function charge(int $amount): array
    {
        return ['charged' => $amount];
    }
}
