<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Attribute\ActivityMethod;

interface HelloActivityInterface
{
    #[ActivityMethod('samples_hello')]
    public function hello(string $name = 'World'): string;
}
