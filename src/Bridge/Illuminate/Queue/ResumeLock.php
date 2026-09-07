<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate\Queue;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * One resume at a time per execution.
 *
 * This is the one thing storage cannot provide, and without it nothing in this package holds
 * together: two workers resuming the **same** execution both replay it, each believing it is
 * discovering the commands the execution produces, and those commands go out twice. The journal
 * does not prevent that — it faithfully records whatever it is given, twice included. The
 * `DbalEventStore` docblock has said so from the start; on the Symfony side it is a Messenger
 * middleware backed by `symfony/lock`, here it is the cache's atomic lock.
 *
 * **Why a closure rather than a job middleware.** A Laravel middleware hooks onto a job class, and
 * this package provides none: these are stores. The integration package will have some, and all it
 * needs is to wrap its `handle()` with this. An artisan command or a hand-written worker gets
 * there too, without having to inherit anything.
 *
 * **Why `LockProvider` and not the cache.** It is the only contract this lock needs, and it comes
 * from `illuminate/contracts`, which `illuminate/database` already pulls in.
 *
 * ⚠ **The type filters nothing, contrary to what this block used to claim.** On Laravel 12, nine
 * stores implement `LockProvider` — `file` included, and it does lock correctly across
 * processes — among them `NullStore`, whose `NoLock::acquire()` returns `true` unconditionally.
 * Measured over twenty resumes of one execution and four `queue:work`: `database` and `file`
 * leave no overlap at all, `array` and `null` leave fifteen out of twenty, at concurrency 4.
 * The choice of store is therefore the caller's, and it is the only one in this package that
 * silently makes a journal diverge when it is wrong.
 *
 * ```php
 * $lock->around($executionId, fn() => $runner->resume($executionId));
 * ```
 *
 * **Why the wait is written here rather than delegated to `Lock::block()`.** `block()` calls a
 * **global** `now()`, which only a full Laravel application defines — `illuminate/support` only
 * publishes it under its own namespace. A package that uses it works inside an application and
 * breaks in a standalone worker or a test, which is the worst of both worlds: the failure only
 * happens where nobody is looking. Eight lines of bounded wait carry no such dependency.
 *
 * ponytail: polling every 100 ms rather than a notification. A resume lock is held for the length
 * of one workflow step; if contention ever justifies it, Redis knows how to notify.
 *
 * ponytail: wait bounded by `$waitSeconds`. A worker that waits for its turn is what we want; a
 * worker that waits indefinitely on a lock that a dead process never released is not — hence the
 * TTL, which is the real safety net.
 *
 * @see \Gplanchat\Bridge\Dbal\Messenger\SingleResumeLockMiddleware the Symfony counterpart
 */
final class ResumeLock
{
    /** Between two attempts: short enough not to hold things up, long enough not to burn CPU. */
    private const POLL_MICROSECONDS = 100_000;

    public function __construct(
        private readonly LockProvider $locks,
        /** The lock's time to live: what releases it if the process holding it dies. */
        private readonly int $ttlSeconds = 300,
        /** How long a worker is willing to wait for its turn before giving up. */
        private readonly int $waitSeconds = 10,
    ) {}

    /**
     * Runs `$work` while holding this execution's lock, and releases it whatever happens.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException if the turn did not come in time
     */
    public function around(string $executionId, callable $work): mixed
    {
        $lock = $this->locks->lock(self::nameFor($executionId), $this->ttlSeconds);
        $deadline = microtime(true) + (float) $this->waitSeconds;

        while (true) {
            if ($lock->get()) {
                try {
                    return $work();
                } finally {
                    $lock->release();
                }
            }

            if (microtime(true) >= $deadline) {
                throw new LockTimeoutException(\sprintf(
                    'Another worker is already resuming %s.',
                    $executionId,
                ));
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * Runs `$work` if the turn is free, and returns `false` without waiting if it is not.
     *
     * **This is the entry point §1.2 measured, and `around()` is the one it disqualifies for
     * a worker.** A Laravel worker is a process, not a coroutine: `around()` holds a slot
     * there for the whole of its wait — fifteen worker-seconds for four seconds of work, over
     * twenty resumes of one and the same execution. And its wait window is a *queue depth*
     * ceiling disguised as a latency setting: as soon as depth × duration exceeds it, it
     * throws.
     *
     * Here the lock only says that the turn is taken. What the caller does with that — queueing it
     * again for later, giving up, journalling — is its decision, not the lock's.
     *
     * @template T
     *
     * @param callable(): T $work
     */
    public function tryAround(string $executionId, callable $work): bool
    {
        $lock = $this->locks->lock(self::nameFor($executionId), $this->ttlSeconds);

        if (!$lock->get()) {
            return false;
        }

        try {
            $work();
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * The name of an execution's lock.
     *
     * Exposed because a caller may want to take it themselves — a command that resumes an execution
     * by hand must take **the same** lock as the worker, and guessing its name is how two processes
     * end up believing they exclude each other when they do not.
     */
    public static function nameFor(string $executionId): string
    {
        return 'durable-resume-' . $executionId;
    }
}
