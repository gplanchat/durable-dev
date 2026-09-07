<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * Billing's answer to `charge`.
 *
 * It carries {@see Cents} and not {@see Money}, because the contract answers
 * `array{receipt: string, charged: int}` and says nothing about a currency. The gap is the other
 * context's to close; until it does, the type refuses to invent one.
 */
final readonly class Receipt
{
    private function __construct(
        public ReceiptNumber $number,
        public Cents $charged,
    ) {}

    /**
     * @param array<string, mixed> $wire what `charge` answered
     */
    public static function fromWire(array $wire): self
    {
        $number = $wire['receipt'] ?? null;
        $charged = $wire['charged'] ?? null;

        if (!\is_string($number) || !\is_int($charged)) {
            throw new \UnexpectedValueException('Billing answered `charge` without a `receipt` string and a `charged` integer. The payload is keyed by name at both ends, so a renamed field arrives as nothing at all.');
        }

        return new self(ReceiptNumber::fromString($number), Cents::of($charged));
    }

    /**
     * @return array{receipt: string, charged: int}
     */
    public function toWire(): array
    {
        return ['receipt' => $this->number->toString(), 'charged' => $this->charged->toInt()];
    }
}
