<?php

declare(strict_types=1);

namespace App\Samples\Workflow\Updates;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Port minimal de samples-php Updates : attend une « update » durable puis salutation avec la valeur reçue.
 */
#[Workflow('Samples_Updates_Greeting')]
final class SamplesUpdatesWorkflow
{
    private readonly ActivityStub $greeting;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->greeting = $environment->activityStub(
            GreetingActivityInterface::class,
        );
    }

    #[WorkflowMethod]
    public function run(): string
    {
        $name = $this->environment->waitUpdate('greet');

        return $this->environment->await($this->greeting->composeGreeting((string) $name));
    }
}
