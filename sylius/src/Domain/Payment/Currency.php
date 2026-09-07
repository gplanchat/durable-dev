<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * An ISO 4217 code, as the billing contract declares it.
 *
 * The contract types it `string`, so something has to decide what a valid one is. Here rather than
 * in the workflow, because a currency the shop cannot name is a shop problem before it is a wire
 * problem.
 */
final readonly class Currency
{
    private function __construct(
        public string $code,
    ) {}

    public static function fromCode(string $code): self
    {
        if (1 !== preg_match('/^[A-Z]{3}$/', $code)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an ISO 4217 code. Billing reads three uppercase letters and nothing else.', $code));
        }

        return new self($code);
    }

    public function code(): string
    {
        return $this->code;
    }
}
