<?php

declare(strict_types=1);

namespace unit\DurableBundle\Fixtures;

use Gplanchat\Durable\Attribute\AsActivityHandler;

#[AsActivityHandler(contract: PremierContract::class)]
final class PremierHandler implements PremierContract
{
    public function __construct()
    {
        CompteurDInstances::note('premier');
    }

    public function faire(string $quoi): string
    {
        return 'premier:' . $quoi;
    }
}
