<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

interface FlakyActivityInterface
{
    #[AsActivityMethod('samples_maybeFail')]
    public function maybeFail(bool $shouldFail = true): string;
}
