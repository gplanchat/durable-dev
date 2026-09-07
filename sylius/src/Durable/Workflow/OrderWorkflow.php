<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Demo\Contracts\Billing\BillingContract;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * The shop has an order billed by the business.
 *
 * Both shapes, in the same workflow and through the same stub. `verify` comes back right away,
 * answered by a method the business wrote; `charge` is fulfilled by a workflow on the other side,
 * which takes some fifteen seconds. **Nothing here tells the two apart.** That is the one point this
 * class exists to show: the caller writes two calls, awaits two results, and does not know which one
 * cost somebody else twelve seconds.
 *
 * While it waits, this workflow holds nothing open: no connection, no process, no transaction. It
 * is not in memory: the worker that resumes it may not be the one that started it.
 */
#[AsWorkflow(self::TYPE)]
final class OrderWorkflow
{
    public const TYPE = 'OrderWorkflow';

    /** The business's endpoint, created by `bin/demo-nexus`. */
    public const ENDPOINT = 'demo-business-billing';

    /** @var NexusStub<BillingContract> */
    private readonly NexusStub $billing;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->billing = $environment->nexusStub(BillingContract::class, endpoint: self::ENDPOINT);
    }

    /**
     * @param int $amount in cents
     *
     * @return array{verified: array{accepted: bool, reason: string|null}, charge: array{receipt: string, charged: int}|null}
     */
    #[AsWorkflowMethod]
    public function run(string $order, int $amount, string $currency = 'EUR'): array
    {
        $verdict = $this->environment->await($this->billing->verify($order, $amount, $currency));

        if (true !== ($verdict['accepted'] ?? false)) {
            // Refused: nothing to charge, and nothing to compensate either.
            return ['verified' => $verdict, 'charge' => null];
        }

        return [
            'verified' => $verdict,
            'charge' => $this->environment->await($this->billing->charge($order, $amount, $currency)),
        ];
    }
}
