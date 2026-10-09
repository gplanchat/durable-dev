<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench\Fixture;

use Gplanchat\Durable\Attribute\AsActivityMethod;

interface SlowGreeter
{
    #[AsActivityMethod(name: 'bench.slow-greet')]
    public function greet(string $name): string;
}
