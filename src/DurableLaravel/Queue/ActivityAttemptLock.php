<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\ActivityAttemptClaimInterface;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * One worker per activity attempt, through the cache lock {@see ResumeLock} uses.
 *
 * Temporal refuses a second start of an attempt on the server; a journal backend has none, and two
 * copies of one job delivered at once both passed the journal guards (#590). A copy that finds the
 * attempt held is deferred, not dropped: the holder
 * journals it, or dies and its claim expires. A worker that dies holding the claim frees it
 * when the TTL expires, and the retried job then runs the attempt.
 *
 * ponytail: the claim is not refreshed while the activity runs, so an attempt longer than the TTL
 * can be started again by a copy; refresh from the activity heartbeat if that ever matters.
 *
 * @see \Gplanchat\Bridge\Dbal\Messenger\LockActivityAttemptClaim the Symfony counterpart
 */
final readonly class ActivityAttemptLock implements ActivityAttemptClaimInterface
{
    public function __construct(
        private readonly LockProvider $locks,
        private readonly int $ttlSeconds = 300,
    ) {}

    public function claim(ExecutionId $executionId, string $activityId, int $attempt): ?\Closure
    {
        $lock = $this->locks->lock(\sprintf('durable-activity-%s-%s-%d', $executionId->toString(), $activityId, $attempt), $this->ttlSeconds);

        return $lock->get() ? static function () use ($lock): void {
            $lock->release();
        } : null;
    }
}
