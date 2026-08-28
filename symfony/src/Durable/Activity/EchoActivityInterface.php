<?php

declare(strict_types=1);

namespace App\Durable\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

interface EchoActivityInterface
{
    #[AsActivityMethod('echoUpper')]
    public function echoUpper(string $text = ''): string;
}
