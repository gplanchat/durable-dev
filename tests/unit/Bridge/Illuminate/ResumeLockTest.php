<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Queue\ResumeLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use PHPUnit\Framework\TestCase;

/**
 * What the lock must guarantee, and what can be proved of it inside a single process.
 *
 * Two workers cannot be launched there. What **can** be proved is what counts: that a second take
 * on the same execution does not get through while the first one holds, and that two different
 * executions never wait on each other. The rest — that a remote worker honours the same lock —
 * hangs on the name, and the name is therefore pinned by a test rather than guessed in two places.
 */
final class ResumeLockTest extends TestCase
{
    public function testTheWorkRunsAndItsValueComesBack(): void
    {
        self::assertSame('repris', $this->lock()->around('exec-1', static fn(): string => 'repris'));
    }

    public function testASecondTakeOnTheSameExecutionDoesNotGetThrough(): void
    {
        $lock = $this->lock(waitSeconds: 0);
        $reentered = false;

        $lock->around('exec-1', function () use ($lock, &$reentered): void {
            try {
                $lock->around('exec-1', static function () use (&$reentered): void {
                    $reentered = true;
                });
            } catch (LockTimeoutException) {
                // Expected: this is exactly what the lock exists to do.
            }
        });

        self::assertFalse($reentered, 'two resumes of the same execution would have crossed');
    }

    public function testTwoDifferentExecutionsNeverWaitOnEachOther(): void
    {
        $lock = $this->lock(waitSeconds: 0);
        $inner = null;

        $lock->around('exec-1', function () use ($lock, &$inner): void {
            $inner = $lock->around('exec-2', static fn(): string => 'passé');
        });

        self::assertSame('passé', $inner, 'the lock is per execution, not global');
    }

    public function testTheLockIsReleasedWhenTheWorkThrows(): void
    {
        $lock = $this->lock(waitSeconds: 0);

        try {
            $lock->around('exec-1', static fn() => throw new \RuntimeException('boum'));
        } catch (\RuntimeException) {
            // We want what comes next, not the exception.
        }

        self::assertSame(
            'repris',
            $lock->around('exec-1', static fn(): string => 'repris'),
            'a resume that fails must not condemn the execution',
        );
    }

    /**
     * The name crosses processes: a worker and a command that resume the same execution must lay
     * down the **same** lock, and each of them guessing it on its own side is the way two
     * processes believe they exclude each other without doing so.
     */
    public function testTheLockNameIsPerExecutionAndStable(): void
    {
        self::assertSame('durable-resume-exec-1', ResumeLock::nameFor('exec-1'));
        self::assertNotSame(ResumeLock::nameFor('exec-1'), ResumeLock::nameFor('exec-2'));
    }

    private function lock(int $waitSeconds = 5): ResumeLock
    {
        return new ResumeLock(new ArrayStore(), ttlSeconds: 300, waitSeconds: $waitSeconds);
    }
}
