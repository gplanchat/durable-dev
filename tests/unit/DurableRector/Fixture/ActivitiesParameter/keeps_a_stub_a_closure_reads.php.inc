<?php

namespace unit\DurableRector\Fixture\ActivitiesParameter;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

// A closure does not see the method's parameters without a `use`.
final class ClosureReadsTheStubWorkflow
{
    private ActivityStub $activities;

    public function __construct(private readonly WorkflowEnvironment $environment)
    {
        $this->activities = $this->environment->activityStub(OrderActivities::class);
    }

    #[AsWorkflowMethod]
    public function run(string $orderId): mixed
    {
        return $this->environment->await($this->environment->async(function () use ($orderId) {
            return $this->environment->await($this->activities->charge($orderId));
        }));
    }
}
