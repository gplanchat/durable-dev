<?php

namespace unit\DurableRector\Fixture\ActivitiesParameter;

use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class LiteralOptionsWorkflow
{
    #[AsWorkflowMethod]
    public function run(string $orderId, WorkflowEnvironment $env): mixed
    {
        $orders = $env->activityStub(OrderActivities::class, ActivityOptions::of(5, 120, 2.0, [\RuntimeException::class], summary: 'Charge', cancellationType: ActivityCancellationType::Abandon));

        return $env->await($orders->charge($orderId));
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\ActivitiesParameter;

use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class LiteralOptionsWorkflow
{
    /**
     * @param \Gplanchat\Durable\Activity\ActivityStub<OrderActivities> $orders
     */
    #[AsWorkflowMethod]
    public function run(string $orderId, WorkflowEnvironment $env, #[\Gplanchat\Durable\Attribute\Activities(OrderActivities::class, attempts: 5, startToClose: 120, initialInterval: 2.0, nonRetryable: [\RuntimeException::class], summary: 'Charge', cancellationType: ActivityCancellationType::Abandon)]
    \Gplanchat\Durable\Activity\ActivityStub $orders): mixed
    {
        return $env->await($orders->charge($orderId));
    }
}

?>
