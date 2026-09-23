<?php

declare(strict_types=1);

namespace unit\DurablePhpstan\Fixtures;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsActivityMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * A fixture analysed by {@see \unit\DurablePhpstan\ActivitiesParameterRuleTest}, never executed.
 */
interface ShippingActivities
{
    #[AsActivityMethod('ship')]
    public function ship(string $orderId): string;
}

#[AsWorkflow(name: 'activities-parameters')]
final class ActivitiesParameters
{
    /**
     * @param ActivityStub<OrderActivities>    $agreeing
     * @param ActivityStub<ShippingActivities> $disagreeing
     */
    #[AsWorkflowMethod]
    public function run(
        string $orderId,
        WorkflowEnvironment $env,
        #[Activities(OrderActivities::class)]
        ActivityStub $agreeing,
        #[Activities(OrderActivities::class)]
        ActivityStub $disagreeing,
        #[Activities(OrderActivities::class)]
        ActivityStub $undocumented,
    ): mixed {
        return $env->await($agreeing->charge($orderId, 100));
    }
}
