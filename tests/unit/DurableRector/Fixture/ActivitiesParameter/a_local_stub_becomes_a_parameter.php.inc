<?php

namespace unit\DurableRector\Fixture\ActivitiesParameter;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class LocalStubWorkflow
{
    #[AsWorkflowMethod]
    public function run(string $orderId, WorkflowEnvironment $env, int $retries = 3): mixed
    {
        $orders = $env->activityStub(OrderActivities::class);

        return $env->await($orders->charge($orderId));
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\ActivitiesParameter;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class LocalStubWorkflow
{
    /**
     * @param \Gplanchat\Durable\Activity\ActivityStub<OrderActivities> $orders
     */
    #[AsWorkflowMethod]
    public function run(string $orderId, WorkflowEnvironment $env, #[\Gplanchat\Durable\Attribute\Activities(OrderActivities::class)]
    \Gplanchat\Durable\Activity\ActivityStub $orders, int $retries = 3): mixed
    {
        return $env->await($orders->charge($orderId));
    }
}

?>
