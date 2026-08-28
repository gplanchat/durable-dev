<?php

declare(strict_types=1);

namespace unit\DurableModule\Fixture;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow(name: 'test.order.place')]
final class OrderWorkflow
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
