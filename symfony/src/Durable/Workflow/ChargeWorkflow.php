<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use App\Durable\Activity\ChargeActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\FulfilsNexusOperation;
use Gplanchat\Durable\Demo\Contracts\Billing\BillingContract;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * What fulfils `billing/charge`.
 *
 * There is no handler method for this operation, and that is the point: the plumbing starts this
 * workflow with the task's callback attached, and the server delivers its result to the caller when
 * it finishes. The `billing` handler is never called back.
 *
 * ⚠ **The parameter names are the interface.** `order`, `amount` and `currency` are the ones
 * `BillingContract::charge()` declares, and the payload is keyed by name on both sides. Renaming
 * one here without renaming it there would hand `null`, with no error and no trace — which is why
 * `NexusFulfilmentParameterNamesTest` compares the two lists.
 */
#[AsWorkflow(self::TYPE)]
#[FulfilsNexusOperation(BillingContract::class, 'charge')]
final class ChargeWorkflow
{
    public const TYPE = 'ChargeWorkflow';

    private readonly ActivityStub $payment;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->payment = $environment->activityStub(
            ChargeActivityInterface::class,
            new ActivityOptions(RetryLimit::ofAttempts(3)),
        );
    }

    /**
     * @return array{receipt: string, charged: int}
     */
    #[AsWorkflowMethod]
    public function run(string $order, int $amount, string $currency): array
    {
        // `sleep()` and not `timer()`: the second one **returns** an awaitable, to await or to
        // compose with `any()`. Calling it without awaiting starts a timer nobody watches, and the
        // workflow carries on — a `TimerStarted` with no `TimerFired` in the history.
        //
        // The delay is there so the wait goes well past the ~9 s of a Nexus task: an operation that
        // could answer within that budget would not need a workflow, and the demonstration would
        // show nothing.
        $this->environment->sleep(12.0);

        return $this->environment->await($this->payment->charge($order, $amount, $currency));
    }
}
