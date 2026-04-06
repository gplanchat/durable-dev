<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Bundle\Attribute\AsDurableActivity;

#[AsDurableActivity(contract: ByeActivityInterface::class)]
final class ByeActivityHandler implements ByeActivityInterface
{
    public function bye(string $name = 'World'): string
    {
        return \sprintf('Bye, %s!', $name);
    }
}
