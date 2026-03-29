<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

interface Event
{
    public function executionId(): string;

    public function payload(): array;
}
