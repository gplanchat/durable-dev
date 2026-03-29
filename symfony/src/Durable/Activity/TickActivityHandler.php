<?php

declare(strict_types=1);

namespace App\Durable\Activity;

use Gplanchat\Durable\Bundle\Attribute\AsDurableActivity;

#[AsDurableActivity(contract: TickActivityInterface::class)]
final class TickActivityHandler implements TickActivityInterface
{
    public function tick(): string
    {
        return 'tick';
    }
}
