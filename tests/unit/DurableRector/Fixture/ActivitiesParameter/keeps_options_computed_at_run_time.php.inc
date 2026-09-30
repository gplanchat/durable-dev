<?php

namespace unit\DurableRector\Fixture\ActivitiesParameter;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class RunTimeOptionsWorkflow
{
    #[AsWorkflowMethod]
    public function run(string $tenant, WorkflowEnvironment $env): mixed
    {
        $byTenant = $env->activityStub(OrderActivities::class, ActivityOptions::of(3, taskQueue: "payments-{$tenant}"));
        $valueObject = $env->activityStub(OrderActivities::class, ActivityOptions::of(RetryLimit::once()));
        $withId = $env->activityStub(OrderActivities::class, ActivityOptions::of(activityId: 'charge'));
        $byString = $env->activityStub(OrderActivities::class, ActivityOptions::of(nonRetryableExceptions: ['RuntimeException']));

        return $env->await($byTenant->charge($tenant), $valueObject->charge($tenant), $withId->charge($tenant), $byString->charge($tenant));
    }
}
