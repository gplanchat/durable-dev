<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Bundle\Attribute\AsDurableActivity;

#[AsDurableActivity(contract: BatchSumActivityInterface::class)]
final class BatchSumActivityHandler implements BatchSumActivityInterface
{
    public function sumParts(array $cents): int
    {
        $sum = 0;
        foreach ($cents as $c) {
            $sum += (int) $c;
        }

        return $sum;
    }
}
