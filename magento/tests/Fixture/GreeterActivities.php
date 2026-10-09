<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench\Fixture;

final class GreeterActivities implements Greeter
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name;
    }
}
