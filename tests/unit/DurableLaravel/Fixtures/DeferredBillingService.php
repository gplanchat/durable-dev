<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;

/**
 * A contract with one operation served straight away and the other fulfilled by a workflow.
 *
 * In a single piece, where the demo's contracts split into two interfaces: what counts here is
 * that a handler has no method for `settle`, and `DeclaredNexusOperations` reads that through
 * `method_exists()`, not through the hierarchy.
 */
#[AsNexusService('deferred-billing')]
interface DeferredBillingService
{
    #[AsNexusOperation('charge')]
    public function charge(int $amount): array;

    #[AsNexusOperation('settle')]
    public function settle(int $amount, string $currency): array;
}
