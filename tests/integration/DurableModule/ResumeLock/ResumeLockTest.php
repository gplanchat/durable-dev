<?php

declare(strict_types=1);

namespace integration\DurableModule\ResumeLock;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The resume lock (#732) against a real MySQL, with real processes: a lock tested inside one
 * process proves nothing, `GET_LOCK` being re-entrant per connection. Not run in CI yet (#738).
 *
 * @internal
 */
final class ResumeLockTest extends TestCase
{
    protected function setUp(): void
    {
        if (null !== $reason = Harness::unavailable()) {
            self::markTestSkipped($reason);
        }
    }

    #[Test]
    public function twoWorkersNeverHoldTheLockAtTheSameTime(): void
    {
        $workers = [new Harness(['getlock', 'race', 'execution-1', '40']), new Harness(['getlock', 'race', 'execution-1', '40'])];

        $slices = [];
        foreach ($workers as $worker) {
            foreach ($worker->events() as [$event, , $enter, $leave]) {
                'SLICE' === $event && $slices[] = [(int) $enter, (int) $leave];
            }
        }
        sort($slices);

        self::assertCount(80, $slices);
        for ($i = 1; $i < \count($slices); ++$i) {
            self::assertGreaterThanOrEqual($slices[$i - 1][1], $slices[$i][0], 'two holders at once');
        }
    }

    #[Test]
    public function aKilledHoldersLockIsTakenByTheNextResumeWithinTwoSeconds(): void
    {
        $holder = new Harness(['getlock', 'hold', 'execution-2']);
        self::assertNotNull($holder->waitFor('HELD'));
        $contender = new Harness(['getlock', 'contend', 'execution-2', '10']);
        self::assertNotNull($contender->waitFor('BLOCKED'), 'the lock was free while the holder lived');

        $killedAt = $holder->kill9();
        $acquired = $contender->waitFor('ACQUIRED');

        self::assertNotNull($acquired);
        // The bound: the server noticing the closed socket.
        self::assertLessThan(2_000, (float) ((int) $acquired[1] - $killedAt) / 1e6);
    }

    #[Test]
    public function aHolderWhoseConnectionWasKilledFindsOutAndTheLockIsFree(): void
    {
        $holder = new Harness(['getlock', 'watch', 'execution-3']);
        $held = $holder->waitFor('HELD');
        self::assertNotNull($held);

        $holder->pdo()->exec('KILL ' . (int) $held[2]);

        self::assertNotNull($holder->waitFor('LOST', 5), 'the adapter reconnected silently and holds() said yes');
        $contender = new Harness(['getlock', 'contend', 'execution-3', '5']);
        self::assertNotNull($contender->waitFor('ACQUIRED'));
    }
}
