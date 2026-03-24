<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use App\Durable\Activity\GreetingActivity;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('ParallelGreetingWorkflow')]
final class ParallelGreetingWorkflow
{
    private readonly ActivityStub $greeting;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->greeting = $environment->activityStub(GreetingActivity::class);
    }

    #[WorkflowMethod]
    public function run(string $first = 'Alice', string $second = 'Bob'): array
    {
        return $this->environment->all(
            $this->greeting->composeGreeting($first),
            $this->greeting->composeGreeting($second),
        );
    }
}
