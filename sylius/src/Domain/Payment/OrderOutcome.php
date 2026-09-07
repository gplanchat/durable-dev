<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * What became of an order the shop tried to have billed.
 *
 * {@see self::toWire()} is the shape `OrderWorkflow` returns and `durable:demo:bill` prints. A
 * workflow result is itself a wire, so the outbound translation belongs here beside the inbound
 * one rather than in the workflow.
 */
final readonly class OrderOutcome
{
    private function __construct(
        public Authorisation $authorisation,
        public ?Receipt $receipt,
    ) {}

    public static function refused(Authorisation $authorisation): self
    {
        return new self($authorisation, null);
    }

    public static function paid(Authorisation $authorisation, Receipt $receipt): self
    {
        return new self($authorisation, $receipt);
    }

    public function isPaid(): bool
    {
        return null !== $this->receipt;
    }

    /**
     * @return array{verified: array{accepted: bool, reason: string|null}, charge: array{receipt: string, charged: int}|null}
     */
    public function toWire(): array
    {
        return [
            'verified' => $this->authorisation->toWire(),
            'charge' => $this->receipt?->toWire(),
        ];
    }
}
