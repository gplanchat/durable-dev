<?php

declare(strict_types=1);

namespace App\Tests\Functional\Fixture;

use Gplanchat\Durable\Attribute\AsActivityMethod;

interface StampActivity
{
    #[AsActivityMethod('stamp')]
    public function stamp(string $what): string;
}
