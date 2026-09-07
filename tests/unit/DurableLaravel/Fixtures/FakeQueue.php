<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Illuminate\Contracts\Queue\Job;

/**
 * A test queue that keeps whatever is pushed onto it. It does not implement the whole contract —
 * only `push`, `later` and `pop` are on the transport's path, and a double that implements what it
 * does not use tells a richer story than the code.
 */
final class FakeQueue
{
    /** @var list<array{job: object, delay: int|null, queue: string|null}> */
    public array $pushed = [];

    /** @param list<Job> $ready */
    public function __construct(private array $ready = []) {}

    public function push(object $job, mixed $data = '', ?string $queue = null): void
    {
        $this->pushed[] = ['job' => $job, 'delay' => null, 'queue' => $queue];
    }

    public function later(int $delay, object $job, mixed $data = '', ?string $queue = null): void
    {
        $this->pushed[] = ['job' => $job, 'delay' => $delay, 'queue' => $queue];
    }

    public function pop(?string $queue = null): ?Job
    {
        return array_shift($this->ready);
    }
}
