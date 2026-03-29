<?php

declare(strict_types=1);

namespace App\Durable\Activity;

use Gplanchat\Durable\Bundle\Attribute\AsDurableActivity;

#[AsDurableActivity(contract: GreetingActivityInterface::class)]
final class GreetingActivityHandler implements GreetingActivityInterface
{
    public function composeGreeting(string $name = 'World'): string
    {
        return \sprintf('Hello, %s!', $name);
    }
}
