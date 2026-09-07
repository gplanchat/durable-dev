<?php

declare(strict_types=1);

namespace unit\DurablePhpstan\Fixtures;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsActivityMethod;
use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\Workflow\ChildWorkflowStub;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * A fixture analysed by {@see \unit\DurablePhpstan\StubMethodsExtensionTest}, never executed.
 *
 * It deliberately holds calls that are correct **and** calls that are wrong: telling those apart is
 * what the extension has to do, and it is what a test content with checking "no errors" would not
 * prove.
 */
interface OrderActivities
{
    #[AsActivityMethod('charge')]
    public function charge(string $orderId, int $amount): string;

    /** No attribute: contract code, not a schedulable operation. */
    public function helper(): string;
}

#[AsNexusService('billing')]
interface BillingServed
{
    #[AsNexusOperation('verify')]
    public function verify(string $order): string;
}

#[AsNexusService('billing')]
interface BillingContract extends BillingServed
{
    #[AsNexusOperation('charge')]
    public function charge(string $order, int $amount): string;

    /** No attribute: contract code, not a callable operation. */
    public function rateCard(): string;
}

#[AsWorkflow(name: 'child')]
final class ChildWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(string $text): string
    {
        return $text;
    }
}

#[AsWorkflow(name: 'call-sites')]
final class StubCallSites
{
    private readonly ActivityStub $orders;

    private readonly ChildWorkflowStub $child;

    private readonly NexusStub $billing;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->orders = $environment->activityStub(OrderActivities::class);
        $this->child = $environment->childWorkflowStub(ChildWorkflow::class);
        $this->billing = $environment->nexusStub(BillingContract::class, 'payments');
    }

    #[AsWorkflowMethod]
    public function run(string $orderId): mixed
    {
        // Correct: declared by the contract and marked.
        $this->environment->await($this->orders->charge($orderId, 100));

        // Correct: the child's entry method.
        $this->environment->await($this->child->run('bonjour'));

        // WRONG — a typo. This is the case the extension exists for: without it, no analysis
        // error, and a BadMethodCallException at run time.
        $this->environment->await($this->orders->chrage($orderId, 100));

        // WRONG — declared by the contract, but without #[AsActivityMethod]: it is not an
        // activity, and the stub refuses it.
        $this->environment->await($this->orders->helper());

        // Correct: declared by the Nexus contract and marked.
        $this->environment->await($this->billing->charge($orderId, 1200));

        // Correct: **inherited** from the served contract. It is the split into two interfaces
        // that makes this case possible, and the extension must follow it as the resolver does.
        $this->environment->await($this->billing->verify($orderId));

        // WRONG — declared by the Nexus contract, but without #[AsNexusOperation].
        $this->environment->await($this->billing->rateCard());

        // WRONG — a typo on a Nexus operation.
        $this->environment->await($this->billing->chagre($orderId, 1200));

        // WRONG — the wrong number of arguments. It only becomes visible because the extension
        // made the method known: that is the second-order gain.
        return $this->environment->await($this->orders->charge($orderId));
    }
}
