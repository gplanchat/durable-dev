<?php

declare(strict_types=1);

namespace unit\DurablePhpstan\Fixtures\StubCouldBeParameter;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * The contract of the rule's data files, autoloadable so that the rule can reflect on it.
 */
interface OrderActivities
{
    #[AsActivityMethod('charge')]
    public function charge(string $orderId, int $amount): string;
}
