<?php

declare(strict_types=1);

namespace integration\Durable\Bundle\Support;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow('OrderWait')]
final class OrderWaitWorkflow
{
    /** @var array<string, mixed>|null the signal payload, set by its handler */
    private ?array $approval = null;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[AsWorkflowMethod]
    public function run(): array
    {
        // `waitSignal()` went away with the condition model: the handler mutates the state and a
        // condition observes it. The handler is re-registered on every pass, replay included.
        $this->environment->onSignal('approved', function (array $payload): void {
            $this->approval = $payload;
        });
        $this->environment->await(fn(): bool => null !== $this->approval);

        return $this->approval;
    }
}
