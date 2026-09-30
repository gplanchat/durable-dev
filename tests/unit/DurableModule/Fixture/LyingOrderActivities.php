<?php

declare(strict_types=1);

namespace unit\DurableModule\Fixture;

use Gplanchat\Durable\Attribute\AsActivityHandler;

/** Names a contract whose methods it does not have. */
#[AsActivityHandler(contract: OrderActivities::class)]
final class LyingOrderActivities {}
