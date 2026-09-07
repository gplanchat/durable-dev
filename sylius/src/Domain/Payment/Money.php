<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * An amount the shop knows the currency of.
 *
 * Cents rather than a float, for the reason the billing contract already gives: a float that
 * crosses a JSON encoding and a decoding is no longer quite the same number.
 */
final readonly class Money
{
    private function __construct(
        public Cents $cents,
        public Currency $currency,
    ) {}

    public static function of(int $cents, string $currency): self
    {
        return new self(Cents::of($cents), Currency::fromCode($currency));
    }

    public function cents(): int
    {
        return $this->cents->toInt();
    }

    public function currency(): Currency
    {
        return $this->currency;
    }
}
