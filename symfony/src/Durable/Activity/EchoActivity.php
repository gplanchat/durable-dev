<?php

declare(strict_types=1);

namespace App\Durable\Activity;

use Gplanchat\Durable\Attribute\ActivityMethod;

interface EchoActivity
{
    #[ActivityMethod('echoUpper')]
    public function echoUpper(string $text = ''): string;
}
