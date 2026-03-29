<?php

declare(strict_types=1);

namespace App\Durable\Activity;

use Gplanchat\Durable\Bundle\Attribute\AsDurableActivity;

#[AsDurableActivity(contract: EchoActivityInterface::class)]
final class EchoActivityHandler implements EchoActivityInterface
{
    public function echoUpper(string $text = ''): string
    {
        return strtoupper($text);
    }
}
