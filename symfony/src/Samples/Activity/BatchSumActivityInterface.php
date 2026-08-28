<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

interface BatchSumActivityInterface
{
    /**
     * @param list<int> $cents
     */
    #[AsActivityMethod('samples_batchSum')]
    public function sumParts(array $cents): int;
}
