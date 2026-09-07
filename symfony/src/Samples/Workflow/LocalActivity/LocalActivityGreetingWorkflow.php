<?php

declare(strict_types=1);

namespace App\Samples\Workflow\LocalActivity;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Inspired by samples-php LocalActivity: tight timeouts (the equivalent of a short start-to-close).
 * On the Durable side, the activity is still scheduled like the others; no distinct "local" stub.
 */
#[AsWorkflow('Samples_LocalActivity_Greeting')]
final class LocalActivityGreetingWorkflow
{
    private readonly ActivityStub $greeting;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->greeting = $environment->activityStub(
            GreetingActivityInterface::class,
            new ActivityOptions(timeouts: ActivityTimeouts::attempt(Duration::seconds(2.0)))
        );
    }

    #[AsWorkflowMethod]
    public function run(string $name = 'World'): string
    {
        return $this->environment->await($this->greeting->composeGreeting($name));
    }
}
