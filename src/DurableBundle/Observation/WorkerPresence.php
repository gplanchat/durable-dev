<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Observation;

use Gplanchat\Bridge\Temporal\Store\TaskQueueKind;
use Gplanchat\Bridge\Temporal\Store\TaskQueuePollers;
use Gplanchat\Bridge\Temporal\Store\TemporalTaskQueueProbe;

/**
 * Who polls each `durable:worker` role's Temporal task queue, for `durable:health` and the
 * dashboards alike.
 *
 * The bundle names the roles that poll the cluster: workflow and activity when Temporal holds the
 * journal, nexus once a handler is declared. A role's values are {@see TaskQueueKind}'s.
 */
final readonly class WorkerPresence
{
    /** A live worker polls about once a minute, and the server keeps a stopped one listed for minutes. */
    public const SILENCE_SECONDS = 120;

    /**
     * @param list<string> $roles
     */
    public function __construct(
        private readonly TemporalTaskQueueProbe $probe,
        private readonly array $roles,
    ) {}

    /**
     * @return array<string, TaskQueuePollers> keyed by `durable:worker --role`
     */
    public function describe(): array
    {
        $described = [];
        foreach ($this->probe->describe(array_map(static fn(string $role): TaskQueueKind => TaskQueueKind::from($role), $this->roles)) as $queue) {
            $described[$queue->kind->value] = $queue;
        }

        return $described;
    }

    /** The moment a role's latest poll must be at or after for its worker to count as there. */
    public function since(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(\sprintf('-%d seconds', self::SILENCE_SECONDS));
    }
}
