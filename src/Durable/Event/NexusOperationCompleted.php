<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Une opération Nexus a répondu.
 *
 * @see NexusOperationScheduled pour l'origine de l'identité
 */
final readonly class NexusOperationCompleted implements Event
{
    public function __construct(
        private string $executionId,
        private string $operationId,
        private mixed $result,
    ) {}

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function operationId(): string
    {
        return $this->operationId;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'operationId' => $this->operationId,
            'result' => $this->result,
        ];
    }
}
