<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Bundle\Attribute\AsDurableActivity;

#[AsDurableActivity(contract: HelloActivityInterface::class)]
final class HelloActivityHandler implements HelloActivityInterface
{
    public function hello(string $name = 'World'): string
    {
        return \sprintf('Hello, %s!', $name);
    }
}
