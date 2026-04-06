<?php

declare(strict_types=1);

namespace App\Samples\Activity;

use Gplanchat\Durable\Attribute\ActivityMethod;

interface AccountTransferActivityInterface
{
    #[ActivityMethod('samples_withdraw')]
    public function withdraw(string $fromAccountId, string $referenceId, int $amountCents): void;

    #[ActivityMethod('samples_deposit')]
    public function deposit(string $toAccountId, string $referenceId, int $amountCents): void;
}
