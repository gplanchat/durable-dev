<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * Billing's answer to `verify`, once it stops being an array.
 *
 * This is the anti-corruption layer's whole job on the inbound side. Above it the shop asks
 * {@see self::isGranted()}; below it, exactly one method knows that the field is called `accepted`
 * and that its absence means no.
 */
final readonly class Authorisation
{
    private function __construct(
        public bool $granted,
        public RefusalReason $reason,
    ) {}

    public static function granted(): self
    {
        return new self(true, RefusalReason::unstated());
    }

    public static function refused(RefusalReason $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * @param array<string, mixed> $wire what `verify` answered
     */
    public static function fromWire(array $wire): self
    {
        if (true !== ($wire['accepted'] ?? false)) {
            return self::refused(RefusalReason::fromWire(\is_string($wire['reason'] ?? null) ? $wire['reason'] : null));
        }

        return self::granted();
    }

    public function isGranted(): bool
    {
        return $this->granted;
    }

    public function reason(): RefusalReason
    {
        return $this->reason;
    }

    /**
     * @return array{accepted: bool, reason: string|null}
     */
    public function toWire(): array
    {
        return ['accepted' => $this->granted, 'reason' => $this->reason->toWire()];
    }
}
