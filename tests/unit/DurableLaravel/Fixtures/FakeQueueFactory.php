<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Illuminate\Contracts\Queue\Factory;

final class FakeQueueFactory implements Factory
{
    public function __construct(private readonly FakeQueue $queue) {}

    // @phpstan-ignore method.childReturnType (FakeQueue implements only what the transport calls, see its docblock)
    public function connection($name = null): FakeQueue
    {
        return $this->queue;
    }
}
