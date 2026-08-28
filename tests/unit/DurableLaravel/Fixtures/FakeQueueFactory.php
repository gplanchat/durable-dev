<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Illuminate\Contracts\Queue\Factory;

final class FakeQueueFactory implements Factory
{
    public function __construct(private readonly FakeQueue $queue) {}

    public function connection($name = null): FakeQueue
    {
        return $this->queue;
    }
}
