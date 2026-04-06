<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Attribute\ActivityMethod;

interface BatchSumActivityInterface
{
    /**
     * @param list<int> $cents
     */
    #[ActivityMethod('samples_batchSum')]
    public function sumParts(array $cents): int;
}
