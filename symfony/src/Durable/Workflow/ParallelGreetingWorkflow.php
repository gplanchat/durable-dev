<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow('ParallelGreetingWorkflow')]
final class ParallelGreetingWorkflow
{
    private readonly ActivityStub $greeting;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->greeting = $environment->activityStub(GreetingActivityInterface::class);
    }

    #[AsWorkflowMethod]
    public function run(string $first = 'Alice', string $second = 'Bob'): array
    {
        return $this->environment->await($this->environment->all(
            $this->greeting->composeGreeting($first),
            $this->greeting->composeGreeting($second),
        ));
    }
}
