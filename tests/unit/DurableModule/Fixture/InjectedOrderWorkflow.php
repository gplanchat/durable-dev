<?php

declare(strict_types=1);

namespace unit\DurableModule\Fixture;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/** OrderWorkflow, with its stub injected as a `#[Activities]` argument instead of built in a constructor. */
#[AsWorkflow(name: 'test.order.place-injected')]
final class InjectedOrderWorkflow
{
    /** @param ActivityStub<OrderActivities> $activities */
    #[AsWorkflowMethod]
    public function run(
        string $orderId,
        #[Activities(OrderActivities::class)]
        ActivityStub $activities,
        WorkflowEnvironment $env,
    ): string {
        $receipt = $env->await($activities->charge($orderId));
        $env->await($activities->reserveStock($orderId));

        return $env->await($activities->notifyCustomer($receipt));
    }
}
