<?php

namespace unit\DurableRector\Fixture\ActivitiesParameter;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class SignalReadsTheStubWorkflow
{
    private ActivityStub $activities;

    public function __construct(private readonly WorkflowEnvironment $environment)
    {
        $this->activities = $this->environment->activityStub(OrderActivities::class);
    }

    #[AsWorkflowMethod]
    public function run(string $orderId): mixed
    {
        return $this->environment->await($this->activities->charge($orderId));
    }

    #[AsSignalMethod]
    public function refund(string $orderId): void
    {
        $this->activities->refund($orderId);
    }
}
