<?php

declare(strict_types=1);

namespace App\Durable\Activity;

use Gplanchat\Durable\Attribute\ActivityMethod;

interface TickActivityInterface
{
    #[ActivityMethod('tick')]
    public function tick(): string;
}
