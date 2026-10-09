<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench\Fixture;

use Gplanchat\Durable\Attribute\AsActivityMethod;

interface Greeter
{
    #[AsActivityMethod(name: 'bench.greet')]
    public function greet(string $name): string;
}
