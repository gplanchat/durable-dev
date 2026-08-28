<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Attribute\AsActivityHandler;

#[AsActivityHandler(contract: AccountTransferActivityInterface::class)]
final class AccountTransferActivityHandler implements AccountTransferActivityInterface
{
    public function withdraw(string $fromAccountId, string $referenceId, int $amountCents): void
    {
    }

    public function deposit(string $toAccountId, string $referenceId, int $amountCents): void
    {
    }
}
