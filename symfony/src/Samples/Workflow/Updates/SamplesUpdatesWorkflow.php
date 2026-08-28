<?php

declare(strict_types=1);

namespace App\Samples\Workflow\Updates;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsUpdateMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Port minimal de samples-php Updates : le handler d'update répond à son appelant *et* mute
 * l'état que le corps attend. La valeur de retour est la réponse — c'est toute la différence
 * avec un signal.
 */
#[AsWorkflow('Samples_Updates_Greeting')]
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

    private ?string $name = null;

    /**
     * @param array<string, mixed> $arguments
     */
    #[AsUpdateMethod('greet')]
    public function greet(array $arguments): string
    {
        $this->name = (string) ($arguments['name'] ?? 'World');

        return $this->name;
    }

    #[AsWorkflowMethod]
    public function run(): string
    {
        $this->environment->await(fn(): bool => null !== $this->name);

        return $this->environment->await($this->greeting->composeGreeting($this->name));
    }
}
