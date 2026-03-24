<?php

declare(strict_types=1);

namespace App\Durable\Activity;

use Gplanchat\Durable\Attribute\ActivityMethod;

interface GreetingActivity
{
    #[ActivityMethod('composeGreeting')]
    public function composeGreeting(string $name = 'World'): string;
}
