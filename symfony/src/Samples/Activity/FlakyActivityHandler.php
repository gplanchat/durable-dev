<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Attribute\AsActivityHandler;
use RuntimeException;

#[AsActivityHandler(contract: FlakyActivityInterface::class)]
final class FlakyActivityHandler implements FlakyActivityInterface
{
    public function maybeFail(bool $shouldFail = true): string
    {
        if ($shouldFail) {
            throw new RuntimeException('Activity failed on purpose (samples-php Exception).');
        }

        return 'ok';
    }
}
