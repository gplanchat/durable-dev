<?php

declare(strict_types=1);

namespace App\Samples\Workflow\Query;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Inspired by samples-php Query: a durable pause then a greeting (the client-side Temporal "queries"
 * are not replayed here; see {@see \Gplanchat\Durable\Query\WorkflowQueryEvaluator} for reading the journal).
 */
#[AsWorkflow('Samples_Query_Greeting')]
final class SamplesQueryWorkflow
{
    private readonly ActivityStub $greeting;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->greeting = $environment->activityStub(
            GreetingActivityInterface::class,
        );
    }

    #[AsWorkflowMethod]
    public function run(string $name = 'World'): string
    {
        $this->environment->timer(2.0);

        return $this->environment->await($this->greeting->composeGreeting($name));
    }
}
