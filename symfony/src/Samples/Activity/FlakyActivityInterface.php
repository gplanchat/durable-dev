<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Attribute\ActivityMethod;

interface FlakyActivityInterface
{
    #[ActivityMethod('samples_maybeFail')]
    public function maybeFail(bool $shouldFail = true): string;
}
