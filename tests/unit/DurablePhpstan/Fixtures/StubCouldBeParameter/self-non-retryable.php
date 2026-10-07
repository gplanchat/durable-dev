<?php

declare(strict_types=1);

namespace unit\DurablePhpstan\Fixtures\StubCouldBeParameter;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class SelfNonRetryable
{
    #[AsWorkflowMethod]
    public function run(string $id, WorkflowEnvironment $env): mixed
    {
        $orders = $env->activityStub(OrderActivities::class, ActivityOptions::of(3, nonRetryableExceptions: [self::class]));

        return $env->await($orders->charge($id, 100));
    }
}
