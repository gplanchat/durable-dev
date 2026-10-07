<?php

declare(strict_types=1);

namespace App\Tests\Functional\Fixture;

final class StampActivityHandler implements StampActivity
{
    public function stamp(string $what): string
    {
        return 'stamped ' . $what;
    }
}
