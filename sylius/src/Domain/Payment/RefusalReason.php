<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * Why billing said no, as a type rather than as whatever text arrived.
 *
 * The contract declares `motif`-shaped free text: `array{accepted: bool, reason: string|null}`. A
 * refusal with no reason is a real answer and not a bug, so the absent case has a name instead of
 * a `null` travelling through the shop.
 */
final readonly class RefusalReason
{
    private function __construct(
        public ?string $text,
    ) {}

    public static function fromWire(?string $text): self
    {
        $text = null === $text ? null : trim($text);

        return new self('' === $text ? null : $text);
    }

    public static function unstated(): self
    {
        return new self(null);
    }

    public function isStated(): bool
    {
        return null !== $this->text;
    }

    public function toWire(): ?string
    {
        return $this->text;
    }
}
