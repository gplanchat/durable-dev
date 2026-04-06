<?php

declare(strict_types=1);

namespace App\Samples\Workflow\SimpleActivity;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Port of temporalio/samples-php `app/src/SimpleActivity` (Greeting workflow + compose activity).
 * Type enregistré : premier argument de {@see Workflow} (`Samples_SimpleActivity_Greeting`).
 */
#[Workflow('Samples_SimpleActivity_Greeting')]
final class SimpleActivityGreetingWorkflow
{
    private readonly ActivityStub $greeting;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->greeting = $environment->activityStub(
            GreetingActivityInterface::class,
            ActivityOptions::default()
                ->withMaxAttempts(3),
        );
    }

    #[WorkflowMethod]
    public function run(string $name = 'World'): string
    {
        return $this->environment->await($this->greeting->composeGreeting($name));
    }
}
