<?php

namespace unit\DurableRector\Fixture\ActivitiesParameter;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

// A bare #[Activities] builds the stub with no options at all, which is not the default policy;
// an empty task queue is "no queue" for of(), and an error for the attribute.
final class DefaultOptionsWorkflow
{
    #[AsWorkflowMethod]
    public function run(string $orderId, WorkflowEnvironment $env): mixed
    {
        $byDefault = $env->activityStub(OrderActivities::class, ActivityOptions::default());
        $empty = $env->activityStub(OrderActivities::class, ActivityOptions::of());
        $nulls = $env->activityStub(OrderActivities::class, ActivityOptions::of(null, null));
        $noQueue = $env->activityStub(OrderActivities::class, ActivityOptions::of(3, taskQueue: ''));

        return $env->await($byDefault->charge($orderId), $empty->charge($orderId), $nulls->charge($orderId), $noQueue->charge($orderId));
    }
}
