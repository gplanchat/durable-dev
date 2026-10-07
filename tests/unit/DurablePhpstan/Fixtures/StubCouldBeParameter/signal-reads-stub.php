<?php

declare(strict_types=1);

namespace unit\DurablePhpstan\Fixtures\StubCouldBeParameter;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class SignalReadsStub
{
    private readonly ActivityStub $orders;

    public function __construct(private readonly WorkflowEnvironment $env)
    {
        $this->orders = $env->activityStub(OrderActivities::class);
    }

    #[AsWorkflowMethod]
    public function run(string $id): mixed
    {
        return $this->env->await($this->orders->charge($id, 100));
    }

    #[AsSignalMethod('cancel')]
    public function cancel(string $id): void
    {
        $this->orders->charge($id, 0);
    }
}
