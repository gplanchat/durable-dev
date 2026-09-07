<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * The shop's own name for an order, which is also what the billing context keys its decisions on.
 *
 * It exists so a method that takes an order and an amount cannot be called with them the wrong way
 * round, and so the one place that turns it back into a string is the adapter that writes the wire.
 */
final readonly class OrderId
{
    private function __construct(
        public string $value,
    ) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);

        if ('' === $value) {
            throw new \InvalidArgumentException('An order id cannot be empty: billing keys its verdicts on it, and an empty key collides with every other empty key.');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
