<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Bundle\Attribute\AsDurableActivity;
use RuntimeException;

#[AsDurableActivity(contract: FlakyActivityInterface::class)]
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
