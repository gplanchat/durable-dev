<?php

declare(strict_types=1);

namespace App\Durable\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * The step that speaks to the payment provider.
 *
 * It is an activity and not workflow code because it is allowed to fail and be retried: that is
 * exactly what an activity is.
 */
interface ChargeActivityInterface
{
    /**
     * @param int $amount in cents
     *
     * @return array{receipt: string, charged: int}
     */
    #[AsActivityMethod('charge')]
    public function charge(string $order, int $amount, string $currency): array;
}
