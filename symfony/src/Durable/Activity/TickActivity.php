<?php

declare(strict_types=1);

namespace App\Durable\Activity;

use Gplanchat\Durable\Attribute\ActivityMethod;

interface TickActivity
{
    #[ActivityMethod('tick')]
    public function tick(): string;
}
