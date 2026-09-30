<?php

namespace unit\DurableRector\Fixture\ActivitiesParameter;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

interface ChargeWorkflowContract
{
    public function run(string $orderId): mixed;
}

// PHP forbids the implementation from adding a required parameter to run().
final class ContractWorkflow implements ChargeWorkflowContract
{
    public function __construct(private readonly WorkflowEnvironment $environment)
    {
    }

    #[AsWorkflowMethod]
    public function run(string $orderId): mixed
    {
        $activities = $this->environment->activityStub(OrderActivities::class);

        return $this->environment->await($activities->charge($orderId));
    }
}
