<?php

namespace unit\DurableRector\Fixture\ActivitiesParameter;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class ReassignedVariableWorkflow
{
    #[AsWorkflowMethod]
    public function run(string $orderId, WorkflowEnvironment $env, bool $express = false): mixed
    {
        $orders = $env->activityStub(OrderActivities::class);
        if ($express) {
            $orders = $env->activityStub(ExpressOrderActivities::class);
        }
        $orderId = $env->activityStub(OrderActivities::class);

        return $env->await($orders->charge($orderId));
    }
}
