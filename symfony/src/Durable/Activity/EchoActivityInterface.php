<?php

declare(strict_types=1);

namespace App\Durable\Activity;

use Gplanchat\Durable\Attribute\ActivityMethod;

interface EchoActivityInterface
{
    #[ActivityMethod('echoUpper')]
    public function echoUpper(string $text = ''): string;
}
