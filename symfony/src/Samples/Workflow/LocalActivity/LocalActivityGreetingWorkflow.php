<?php

declare(strict_types=1);

namespace App\Samples\Workflow\LocalActivity;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Inspiré de samples-php LocalActivity : timeouts serrés (équivalent start-to-close court).
 * Côté Durable, l’activité reste planifiée comme les autres ; pas de stub « local » distinct.
 */
#[Workflow('Samples_LocalActivity_Greeting')]
final class LocalActivityGreetingWorkflow
{
    private readonly ActivityStub $greeting;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->greeting = $environment->activityStub(
            GreetingActivityInterface::class,
            ActivityOptions::default()
                ->withStartToCloseTimeoutSeconds(2.0)
        );
    }

    #[WorkflowMethod]
    public function run(string $name = 'World'): string
    {
        return $this->environment->await($this->greeting->composeGreeting($name));
    }
}
