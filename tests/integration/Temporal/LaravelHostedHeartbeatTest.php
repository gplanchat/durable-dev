<?php

declare(strict_types=1);

namespace integration\Temporal;

/**
 * The heartbeat scenarios with the activities hosted by the Laravel integration's activity worker
 * (see Hosts/laravel-activity.php).
 */
final class LaravelHostedHeartbeatTest extends HeartbeatOnAHostTestCase
{
    protected function activityWorkerRole(): string
    {
        return 'laravel-activity';
    }
}
