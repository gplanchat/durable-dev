<?php

declare(strict_types=1);

namespace App\Durable\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

interface TickActivityInterface
{
    #[AsActivityMethod('tick')]
    public function tick(): string;
}
