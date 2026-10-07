<?php

declare(strict_types=1);

namespace unit\DurablePhpstan\Fixtures\StubCouldBeParameter;

use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class LiteralOptions
{
    #[AsWorkflowMethod]
    public function run(string $id, WorkflowEnvironment $env): mixed
    {
        $orders = $env->activityStub(OrderActivities::class, ActivityOptions::of(5, 120, 2.5, [\RuntimeException::class], 'billing', 3.0, 60.0, 'Charge', null, ActivityCancellationType::Abandon));

        return $env->await($orders->charge($id, 100));
    }
}
