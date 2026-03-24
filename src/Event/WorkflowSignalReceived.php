<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Signal reçu par l’exécution (ordre dans le journal = ordre des {@see \Gplanchat\Durable\ExecutionContext::waitSignal}).
 */
final readonly class WorkflowSignalReceived implements Event
{
    public function __construct(
        private string $executionId,
        private string $signalName,
        private array $payload,
    ) {
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function signalName(): string
    {
        return $this->signalName;
    }

    /** @return array<mixed> */
    public function payload(): array
    {
        return [
            'signalName' => $this->signalName,
            'signalPayload' => $this->payload,
        ];
    }

    /** Arguments métier du signal (distinct du {@see Event::payload()} de persistance). */
    public function signalPayload(): array
    {
        return $this->payload;
    }
}
