<?php

declare(strict_types=1);

namespace App\Samples\Workflow\Signal;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Port minimal de samples-php Signal : attend un signal puis compose la salutation avec le payload.
 */
#[Workflow('Samples_Signal_Approve')]
final class SamplesSignalWorkflow
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
        $payload = $this->environment->waitSignal('approve');
        $name = \is_array($payload) ? ($payload['name'] ?? 'World') : 'World';

        return $this->environment->await($this->greeting->composeGreeting($name));
    }
}
