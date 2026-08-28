<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Illuminate\Contracts\Queue\Job;

/**
 * Une file de test qui retient ce qu'on lui pousse. Elle n'implémente pas le contrat entier —
 * seules `push`, `later` et `pop` sont sur le chemin du transport, et un double qui implémente ce
 * qu'il n'utilise pas raconte une histoire plus riche que le code.
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
