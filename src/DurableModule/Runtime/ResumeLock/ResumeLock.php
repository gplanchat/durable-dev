<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime\ResumeLock;

/**
 * The per-execution lock a Magento SQL worker takes before it resumes an execution (#732).
 *
 * Non-blocking: a held lock is not waited on, the caller re-queues the message. One instance is
 * one worker, so it is not re-entrant: a second `tryAcquire()` of a key the instance already holds
 * returns false.
 */
interface ResumeLock
{
    public function tryAcquire(string $key): bool;

    /**
     * Whether this instance still owns the lock, checked on the server. A worker checks it before
     * a side effect it cannot take back: the lock can be lost without the worker noticing.
     */
    public function holds(string $key): bool;

    public function release(string $key): void;
}
