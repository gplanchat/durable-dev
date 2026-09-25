<?php

declare(strict_types=1);

namespace integration\Temporal\Fixtures;

use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;

/**
 * Takes its sender the way an application's activity does: injected by the host's container (#510).
 */
final class HeartbeatingActivities implements HeartbeatActivities
{
    private const MAX_SECONDS = 60;

    public function __construct(private readonly ActivityHeartbeatSenderInterface $heartbeat) {}

    public function heartbeatFor(int $seconds): int
    {
        for ($beat = 1; $beat <= $seconds; ++$beat) {
            sleep(1);
            $this->heartbeat->sendHeartbeat(['beat' => $beat]);
        }

        return $seconds;
    }

    public function heartbeatUntilCancelled(string $marker): string
    {
        for ($beat = 1; $beat <= self::MAX_SECONDS; ++$beat) {
            sleep(1);
            if ($this->heartbeat->sendHeartbeat(['beat' => $beat])) {
                file_put_contents($marker, 'cancel-requested');

                return 'cancelled';
            }
        }
        file_put_contents($marker, 'never-cancelled');

        return 'never cancelled';
    }
}
