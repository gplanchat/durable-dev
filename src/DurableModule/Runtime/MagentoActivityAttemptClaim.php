<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\ActivityAttemptClaimInterface;
use Gplanchat\DurableModule\Runtime\ResumeLock\ResumeLock;

/**
 * One worker per activity attempt, on the lock the resume uses (#753): Magento ships no
 * symfony/lock, and its `LockManagerInterface` takes `GET_LOCK` on the shop's connection and answers
 * `true` without locking when the database is down (DUR046).
 *
 * A copy that finds the attempt held gets null and is deferred, not dropped. There is no TTL: with
 * `GET_LOCK` the claim lasts as long as the holder's connection, and the server frees it when that
 * connection ends, so a killed holder frees its attempt in milliseconds (#732). A holder that hangs
 * with its connection open keeps the attempt until the server drops the session, longer than the
 * table queue's lease (#731): the redelivered copy is deferred again until then.
 *
 * The claim can be lost without the holder noticing: Magento's adapter reconnects silently and the
 * new session holds nothing. Call {@see holds()} before a side effect that cannot be taken back.
 */
final class MagentoActivityAttemptClaim implements ActivityAttemptClaimInterface
{
    public function __construct(private readonly ResumeLock $lock) {}

    public function claim(ExecutionId $executionId, string $activityId, int $attempt): ?\Closure
    {
        $key = self::key($executionId, $activityId, $attempt);

        return $this->lock->tryAcquire($key) ? fn() => $this->lock->release($key) : null;
    }

    /** Whether this worker still owns the attempt, checked on the server. */
    public function holds(ExecutionId $executionId, string $activityId, int $attempt): bool
    {
        return $this->lock->holds(self::key($executionId, $activityId, $attempt));
    }

    private static function key(ExecutionId $executionId, string $activityId, int $attempt): string
    {
        return \sprintf('activity-attempt:%s:%s:%d', $executionId->toString(), $activityId, $attempt);
    }
}
