<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;

#[AsNexusService('billing')]
interface BillingService
{
    /** @return array<string, mixed> */
    #[AsNexusOperation('charge')]
    public function charge(int $amount): array;
}
