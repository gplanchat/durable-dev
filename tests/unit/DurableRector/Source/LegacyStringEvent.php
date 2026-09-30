<?php

declare(strict_types=1);

namespace unit\DurableRector\Source;

use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\ExecutionId;

/**
 * A custom event its author has not retyped yet: its constructor still takes a string.
 */
final readonly class LegacyStringEvent implements Event
{
    public function __construct(private string $executionId) {}

    public function executionId(): ExecutionId
    {
        return ExecutionId::fromString($this->executionId);
    }

    public function payload(): array
    {
        return [];
    }
}
