<?php

declare(strict_types=1);

namespace unit\DurablePhpstan\Fixtures\StubCouldBeParameter;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

interface ChargeContract
{
    public function run(string $id, WorkflowEnvironment $env): mixed;
}

final class ContractInterface implements ChargeContract
{
    #[AsWorkflowMethod]
    public function run(string $id, WorkflowEnvironment $env): mixed
    {
        $orders = $env->activityStub(OrderActivities::class);

        return $env->await($orders->charge($id, 100));
    }
}
