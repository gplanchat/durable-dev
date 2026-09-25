<?php

declare(strict_types=1);

namespace integration\Temporal\Fixtures;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * The activities that heartbeat, hosted by a framework's own activity worker rather than by the
 * suite's `worker.php` (#518).
 */
interface HeartbeatActivities
{
    /** Heartbeats once a second for `$seconds`, then returns how many beats it sent. */
    #[AsActivityMethod('heartbeat.for')]
    public function heartbeatFor(int $seconds): int;

    /**
     * Heartbeats once a second until a heartbeat answers that the server asked for cancellation,
     * then writes it to `$marker`: the test reads the file, since a cancelled activity's result
     * reaches no one.
     */
    #[AsActivityMethod('heartbeat.until_cancelled')]
    public function heartbeatUntilCancelled(string $marker): string;
}
