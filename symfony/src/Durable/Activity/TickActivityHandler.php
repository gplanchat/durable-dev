<?php

declare(strict_types=1);

namespace App\Durable\Activity;

use Gplanchat\Durable\Attribute\AsActivityHandler;

#[AsActivityHandler(contract: TickActivityInterface::class)]
final class TickActivityHandler implements TickActivityInterface
{
    public function tick(): string
    {
        return 'tick';
    }
}
