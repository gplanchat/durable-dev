<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Illuminate\Contracts\Queue\Job;

/**
 * Le contrat `Job` en entier, parce que le transport le type — mais seules `payload()` et
 * `delete()` sont sur son chemin. Le reste est là pour que PHP soit content, et le dire vaut mieux
 * que de faire croire à un double riche.
 */
final class FakeJob implements Job
{
    public bool $deleted = false;

    public function __construct(private readonly object $command) {}

    /** @return array{data: array{command: string}} */
    public function payload(): array
    {
        return ['data' => ['command' => serialize($this->command)]];
    }

    public function delete(): void
    {
        $this->deleted = true;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function uuid(): ?string
    {
        return null;
    }
    public function getJobId(): ?string
    {
        return 'fake';
    }
    public function fire(): void {}
    public function release($delay = 0): void {}
    public function isReleased(): bool
    {
        return false;
    }
    public function isDeletedOrReleased(): bool
    {
        return $this->deleted;
    }
    public function attempts(): int
    {
        return 1;
    }
    public function hasFailed(): bool
    {
        return false;
    }
    public function markAsFailed(): void {}
    public function fail($e = null): void {}
    public function maxTries(): ?int
    {
        return null;
    }
    public function maxExceptions(): ?int
    {
        return null;
    }
    public function timeout(): ?int
    {
        return null;
    }
    public function retryUntil(): ?int
    {
        return null;
    }
    public function getName(): string
    {
        return self::class;
    }
    public function resolveName(): string
    {
        return self::class;
    }
    public function resolveQueuedJobClass(): string
    {
        return self::class;
    }
    public function getConnectionName(): string
    {
        return 'fake';
    }
    public function getQueue(): string
    {
        return 'default';
    }
    public function getRawBody(): string
    {
        return json_encode($this->payload(), JSON_THROW_ON_ERROR);
    }
}
