<?php

declare(strict_types=1);

namespace App\Samples\Workflow\Signal;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Minimal port of samples-php Signal: a handler receives the signal, the body resumes when
 * the state it mutated satisfies its condition, then composes the greeting.
 */
#[AsWorkflow('Samples_Signal_Approve')]
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
    #[AsSignalMethod('approve')]
    public function onApprove(array $payload): void
    {
        $this->name = (string) ($payload['name'] ?? 'World');
    }

    #[AsWorkflowMethod]
    public function run(): string
    {
        // On a property, the short form is enough: it is `$this` that gets captured, not the value.
        $this->environment->await(fn(): bool => null !== $this->name);

        return $this->environment->await($this->greeting->composeGreeting($this->name));
    }
}
