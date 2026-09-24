<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime;

use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;

/**
 * The heartbeat sender the ObjectManager hands to activities (`di.xml` prefers it).
 *
 * The activities are built by the ObjectManager, the Temporal worker by {@see RuntimeFactory}, which
 * binds each task's token onto its own sender. This one leads to that sender once the factory has
 * built it, and is a no-op until then: in-process, without a cluster, nothing serves heartbeats.
 */
final class SharedActivityHeartbeatSender implements ActivityHeartbeatSenderInterface
{
    private ActivityHeartbeatSenderInterface $inner;

    public function __construct()
    {
        $this->inner = new NullActivityHeartbeatSender();
    }

    public function delegateTo(ActivityHeartbeatSenderInterface $sender): void
    {
        $this->inner = $sender;
    }

    public function inner(): ActivityHeartbeatSenderInterface
    {
        return $this->inner;
    }

    public function sendHeartbeat(mixed $details = null): bool
    {
        return $this->inner->sendHeartbeat($details);
    }

    public function isCancellationRequested(): bool
    {
        return $this->inner->isCancellationRequested();
    }
}
