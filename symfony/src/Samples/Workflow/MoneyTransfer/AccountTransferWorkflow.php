<?php

declare(strict_types=1);

namespace App\Samples\Workflow\MoneyTransfer;

use App\Samples\Activity\AccountTransferActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('Samples_MoneyTransfer_Account')]
final class AccountTransferWorkflow
{
    private readonly ActivityStub $withdraw;

    private readonly ActivityStub $deposit;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->withdraw = $environment->activityStub(
            AccountTransferActivityInterface::class,
            ActivityOptions::default()->withMaxAttempts(10)->withStartToCloseTimeoutSeconds(5.0),
        );
        $this->deposit = $environment->activityStub(
            AccountTransferActivityInterface::class,
            ActivityOptions::default()->withMaxAttempts(10)->withStartToCloseTimeoutSeconds(5.0),
        );
    }

    #[WorkflowMethod]
    public function run(
        string $fromAccountId = 'from',
        string $toAccountId = 'to',
        string $referenceId = 'ref-1',
        int $amountCents = 100,
    ): string {
        $this->environment->await($this->withdraw->withdraw($fromAccountId, $referenceId, $amountCents));
        $this->environment->await($this->deposit->deposit($toAccountId, $referenceId, $amountCents));

        return 'transfer_ok';
    }
}
