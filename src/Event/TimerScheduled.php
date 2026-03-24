<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

final readonly class TimerScheduled implements Event
{
    public function __construct(
        private string $executionId,
        private string $timerId,
        private float $scheduledAt,
    ) {
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function timerId(): string
    {
        return $this->timerId;
    }

    public function scheduledAt(): float
    {
        return $this->scheduledAt;
    }

    public function payload(): array
    {
        return [
            'timerId' => $this->timerId,
            'scheduledAt' => $this->scheduledAt,
        ];
    }
}
