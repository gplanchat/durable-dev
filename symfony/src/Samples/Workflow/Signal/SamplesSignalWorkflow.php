<?php

declare(strict_types=1);

namespace App\Samples\Workflow\Signal;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\SignalMethod;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Port minimal de samples-php Signal : un handler reçoit le signal, le corps reprend quand l'état
 * qu'il a muté satisfait sa condition, puis compose la salutation.
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

    private ?string $name = null;

    /**
     * @param array<string, mixed> $payload
     */
    #[SignalMethod('approve')]
    public function onApprove(array $payload): void
    {
        $this->name = (string) ($payload['name'] ?? 'World');
    }

    #[WorkflowMethod]
    public function run(): string
    {
        // Sur une propriété, la forme courte suffit : c'est `$this` qui est capturé, pas la valeur.
        $this->environment->await(fn(): bool => null !== $this->name);

        return $this->environment->await($this->greeting->composeGreeting($this->name));
    }
}
