<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class ChargesAnOrder
{
    #[AsWorkflowMethod]
    public function run(string $orderId, WorkflowEnvironment $env): mixed
    {
        $orders = $env->activityStub(OrderActivities::class);

        return $env->await($orders->charge($orderId));
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class ChargesAnOrder
{
    /**
     * @param \Gplanchat\Durable\Activity\ActivityStub<OrderActivities> $orders
     */
    #[AsWorkflowMethod]
    public function run(string $orderId, WorkflowEnvironment $env, #[\Gplanchat\Durable\Attribute\Activities(OrderActivities::class)]
    \Gplanchat\Durable\Activity\ActivityStub $orders): mixed
    {
        return $env->await($orders->charge($orderId));
    }
}

?>
