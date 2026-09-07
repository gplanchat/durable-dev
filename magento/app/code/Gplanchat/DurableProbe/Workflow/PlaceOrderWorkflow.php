<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\DurableProbe\Workflow\Activity\OrderActivities;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * An ordinary workflow — and that is the whole argument.
 *
 * Nothing here knows it runs inside Magento: no framework import, no
 * `ObjectManager`, no `ResourceConnection`. The same class runs under the
 * Symfony bundle without being touched, because everything below the ports is
 * `gplanchat/durable` unchanged.
 */
#[AsWorkflow(name: 'durable.demo.place-order')]
final class PlaceOrderWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(string $orderId): string
    {
        $activities = $this->environment->activityStub(OrderActivities::class);

        $receipt = $this->environment->await($activities->charge($orderId));
        $this->environment->await($activities->reserveStock($orderId));

        return $this->environment->await($activities->notifyCustomer($receipt));
    }
}
