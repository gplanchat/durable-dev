<?php

declare(strict_types=1);

namespace unit\DurablePhpstan\Fixtures\StubCouldBeParameter;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class ContractWithoutActivityMethod
{
    #[AsWorkflowMethod]
    public function run(string $id, WorkflowEnvironment $env): mixed
    {
        $orders = $env->activityStub(\Countable::class);

        return $env->await($orders->charge($id, 100));
    }
}
