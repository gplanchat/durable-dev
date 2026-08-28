<?php

declare(strict_types=1);

namespace App\Durable\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

interface GreetingActivityInterface
{
    #[AsActivityMethod('composeGreeting')]
    public function composeGreeting(string $name = 'World'): string;
}
