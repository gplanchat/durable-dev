<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

interface AccountTransferActivityInterface
{
    #[AsActivityMethod('samples_withdraw')]
    public function withdraw(string $fromAccountId, string $referenceId, int $amountCents): void;

    #[AsActivityMethod('samples_deposit')]
    public function deposit(string $toAccountId, string $referenceId, int $amountCents): void;
}
