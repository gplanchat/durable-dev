<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Attribute\ActivityMethod;

interface ByeActivityInterface
{
    #[ActivityMethod('samples_bye')]
    public function bye(string $name = 'World'): string;
}
