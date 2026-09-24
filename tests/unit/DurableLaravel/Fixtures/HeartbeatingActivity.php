<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;

/** The activities page's example, down to what the container has to supply. */
final class HeartbeatingActivity
{
    public function __construct(public readonly ActivityHeartbeatSenderInterface $heartbeat) {}
}
