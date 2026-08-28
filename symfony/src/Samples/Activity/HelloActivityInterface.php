<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

interface HelloActivityInterface
{
    #[AsActivityMethod('samples_hello')]
    public function hello(string $name = 'World'): string;
}
