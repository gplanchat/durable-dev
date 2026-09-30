<?php

namespace unit\DurableRector\Fixture\ActivitiesParameter;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

// Durable injects activity stubs only; a closure workflow receives the environment only.
final class OtherStubsWorkflow
{
    #[AsWorkflowMethod]
    public function run(string $orderId, WorkflowEnvironment $env): mixed
    {
        $child = $env->childWorkflowStub(ShippingWorkflow::class);
        $nexus = $env->nexusStub(PaymentService::class, 'payments');

        return $env->await($child->run($orderId), $nexus->charge($orderId));
    }

    public function inTheHarness(\Closure $run): mixed
    {
        return $run(function (WorkflowEnvironment $env): mixed {
            $orders = $env->activityStub(OrderActivities::class);

            return $env->await($orders->charge('order-1'));
        });
    }
}
