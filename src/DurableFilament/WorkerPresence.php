<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament;

use Gplanchat\Bridge\Temporal\Store\TaskQueuePollers;
use Gplanchat\Bridge\Temporal\Store\TemporalTaskQueueProbe;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Who polls each worker role's queue, for the list page, as the Sylius dashboard shows it.
 *
 * On Temporal, the cluster lists the pollers of the workflow and activity task queues, read through
 * the bridge's probe over the connection and client durable-laravel binds. Laravel's queue keeps no list of the
 * processes that run `queue:work`, so the Illuminate backend gets one "could not ask" row.
 *
 * @internal
 */
final readonly class WorkerPresence
{
    /** A live worker polls about once a minute, and the server keeps a stopped one listed for minutes. */
    public const SILENCE_SECONDS = 120;

    /**
     * @param \Closure(): list<array{role: string, lastPolledAt: ?\DateTimeImmutable, error: ?string}> $describe
     */
    public function __construct(private \Closure $describe) {}

    public static function of(Container $app): self
    {
        if (!class_exists(TemporalTaskQueueProbe::class) || !$app->bound(TemporalConnection::class) || !$app->bound(WorkflowServiceClientInterface::class)) {
            return new self(static fn(): array => [
                ['role' => 'queue', 'lastPolledAt' => null, 'error' => (string) __('durable-filament::durable.workers.queue_unlisted')],
            ]);
        }

        return new self(static fn(): array => array_map(
            static fn(TaskQueuePollers $queue): array => ['role' => $queue->kind->value, 'lastPolledAt' => $queue->lastPolledAt, 'error' => $queue->error],
            (new TemporalTaskQueueProbe($app->make(WorkflowServiceClientInterface::class), $app->make(TemporalConnection::class)))->describe(),
        ));
    }

    /**
     * @return list<array{role: string, polling: bool, error: ?string, seconds: int}>
     */
    public function rows(): array
    {
        $since = new \DateTimeImmutable(\sprintf('-%d seconds', self::SILENCE_SECONDS));

        return array_map(static fn(array $queue): array => [
            'role' => $queue['role'],
            'polling' => null !== $queue['lastPolledAt'] && $queue['lastPolledAt'] >= $since,
            'error' => $queue['error'],
            'seconds' => self::SILENCE_SECONDS,
        ], ($this->describe)());
    }
}
