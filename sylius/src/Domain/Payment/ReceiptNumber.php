<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * What billing calls the thing it hands back when money moved.
 */
final readonly class ReceiptNumber
{
    private function __construct(
        public string $value,
    ) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);

        if ('' === $value) {
            throw new \InvalidArgumentException('A receipt with no number is not a receipt: billing charged, and the shop cannot say against what.');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
