<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Messenger;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\ActivityAttemptClaimInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * One worker per activity attempt, through the same lock store as the resume lock.
 *
 * Temporal refuses a second start of an attempt on the server; the DBAL backend has none, and two
 * copies of one message delivered at once both passed the journal guards (#590). A copy that finds
 * the attempt held is deferred, not dropped: the holder
 * journals it, or dies and its claim expires. The TTL is the resume lock's
 * (`durable.dbal.lock_ttl`): a worker that dies holding the claim frees it when it expires, and the
 * redelivered message then runs the attempt.
 *
 * ponytail: the claim is not refreshed while the activity runs, so an attempt longer than the TTL
 * can be started again by a copy; refresh from the activity heartbeat if that ever matters.
 */
final readonly class LockActivityAttemptClaim implements ActivityAttemptClaimInterface
{
    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly float $ttlSeconds = 300.0,
    ) {}

    public function claim(ExecutionId $executionId, string $activityId, int $attempt): ?\Closure
    {
        $lock = $this->lockFactory->createLock(\sprintf('durable-activity-%s-%s-%d', $executionId->toString(), $activityId, $attempt), $this->ttlSeconds);

        return $lock->acquire() ? $lock->release(...) : null;
    }
}
