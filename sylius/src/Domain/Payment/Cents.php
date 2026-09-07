<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * An amount in minor units, with no currency attached.
 *
 * It exists because `charge` answers `array{receipt: string, charged: int}`: an amount, and no
 * currency. Rebuilding a {@see Money} from the currency we *asked* for would assume an answer the
 * billing context never gave, so what comes back is this instead.
 */
final readonly class Cents
{
    private function __construct(
        public int $amount,
    ) {}

    public static function of(int $amount): self
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException(\sprintf('An amount in cents cannot be negative, got %d.', $amount));
        }

        return new self($amount);
    }

    public function toInt(): int
    {
        return $this->amount;
    }
}
