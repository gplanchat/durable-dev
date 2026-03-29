<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

final readonly class ExecutionStarted implements Event
{
    public function __construct(
        private string $executionId,
        private array $payload = [],
    ) {
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function payload(): array
    {
        return $this->payload;
    }
}
