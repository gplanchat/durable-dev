<?php

declare(strict_types=1);

namespace App\Durable\Activity;

use Gplanchat\Durable\Attribute\AsActivityHandler;

/**
 * The demonstration's payment provider: it says yes, and it takes its time.
 *
 * The slowness is not decorative. It is what makes the wait **real**: without it the demonstration
 * would show an immediate round trip dressed up as a deferred one, and the reader would not see the
 * one thing the deferred shape exists to show — the caller holding nothing open meanwhile.
 */
#[AsActivityHandler(contract: ChargeActivityInterface::class)]
final class ChargeActivityHandler implements ChargeActivityInterface
{
    /**
     * @return array{receipt: string, charged: int}
     */
    public function charge(string $order, int $amount, string $currency): array
    {
        usleep(1_500_000);

        return [
            'receipt' => \sprintf('RECEIPT-%s-%s', strtoupper($currency), $order),
            'charged' => $amount,
        ];
    }
}
