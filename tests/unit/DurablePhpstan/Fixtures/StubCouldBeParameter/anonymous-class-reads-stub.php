<?php

declare(strict_types=1);

namespace unit\DurablePhpstan\Fixtures\StubCouldBeParameter;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class AnonymousClassReadsStub
{
    private readonly ActivityStub $orders;

    public function __construct(private readonly WorkflowEnvironment $env)
    {
        $this->orders = $env->activityStub(OrderActivities::class);
    }

    #[AsWorkflowMethod]
    public function run(string $id): mixed
    {
        $inner = new class {
            public string $orders = 'inner';

            public function get(): string
            {
                return $this->orders;
            }
        };

        return [$inner->get(), $this->env->await($this->orders->charge($id, 100))];
    }
}
