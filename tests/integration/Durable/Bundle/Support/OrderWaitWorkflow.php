<?php

declare(strict_types=1);

namespace integration\Durable\Bundle\Support;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow('OrderWait')]
final class OrderWaitWorkflow
{
    /** @var array<string, mixed>|null la charge du signal, posée par son handler */
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
        // `waitSignal()` a disparu avec le modèle des conditions : le handler mute l'état, une
        // condition l'observe. Le handler est réenregistré à chaque passe, replay compris.
        $this->environment->onSignal('approved', function (array $payload): void {
            $this->approval = $payload;
        });
        $this->environment->await(fn(): bool => null !== $this->approval);

        return $this->approval;
    }
}
