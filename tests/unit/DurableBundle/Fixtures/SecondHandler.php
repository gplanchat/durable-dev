<?php

declare(strict_types=1);

namespace unit\DurableBundle\Fixtures;

use Gplanchat\Durable\Attribute\AsActivityHandler;

#[AsActivityHandler(contract: SecondContract::class)]
final class SecondHandler implements SecondContract
{
    public function __construct()
    {
        CompteurDInstances::note('second');
    }

    public function faire(string $quoi): string
    {
        return 'second:' . $quoi;
    }
}
