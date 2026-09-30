<?php

namespace unit\DurableRector\Fixture\ActivitiesParameter;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class ConstructorStubWorkflow
{
    private readonly ActivityStub $activities;

    public function __construct(private readonly WorkflowEnvironment $environment)
    {
        $this->activities = $this->environment->activityStub(OrderActivities::class);
    }

    /**
     * Charges the order.
     */
    #[AsWorkflowMethod]
    public function run(string $orderId): mixed
    {
        return $this->environment->await($this->activities->charge($orderId));
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\ActivitiesParameter;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class ConstructorStubWorkflow
{
    public function __construct(private readonly WorkflowEnvironment $environment)
    {
    }

    /**
     * Charges the order.
     * @param \Gplanchat\Durable\Activity\ActivityStub<OrderActivities> $activities
     */
    #[AsWorkflowMethod]
    public function run(string $orderId, #[\Gplanchat\Durable\Attribute\Activities(OrderActivities::class)]
    \Gplanchat\Durable\Activity\ActivityStub $activities): mixed
    {
        return $this->environment->await($activities->charge($orderId));
    }
}

?>
