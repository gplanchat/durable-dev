<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('GreetingWorkflow')]
final class GreetingWorkflow
{
    private readonly ActivityStub $greeting;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        // Stubs initialisés au constructeur ; options retry/gestion d'erreur configurables ici
        $this->greeting = $environment->activityStub(
            GreetingActivityInterface::class,
            ActivityOptions::default()->withMaxAttempts(3),
        );
    }

    #[WorkflowMethod]
    public function run(string $name = 'World'): string
    {
        return $this->environment->await($this->greeting->composeGreeting($name));
    }
}
