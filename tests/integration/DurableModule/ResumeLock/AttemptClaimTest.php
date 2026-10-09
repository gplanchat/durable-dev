<?php

declare(strict_types=1);

namespace integration\DurableModule\ResumeLock;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The activity attempt claim (#753) against a real MySQL, claimants as separate processes. Not run
 * in CI yet (#738).
 *
 * @internal
 */
final class AttemptClaimTest extends TestCase
{
    protected function setUp(): void
    {
        if (null !== $reason = Harness::unavailable()) {
            self::markTestSkipped($reason);
        }
    }

    #[Test]
    public function onlyOneClaimOfAnAttemptSucceedsWhileItIsHeld(): void
    {
        $holder = self::claimant('hold', 'exec-1', 'act-1', 1);
        self::assertNotNull($holder->waitFor('HELD'));

        self::assertNotNull(self::claimant('contend', 'exec-1', 'act-1', 1)->waitFor('DEFERRED'), 'a second copy ran the attempt');
        self::assertNotNull(self::claimant('contend', 'exec-1', 'act-1', 2)->waitFor('CLAIMED'), 'the next attempt must not wait for this one');
        self::assertNotNull(self::claimant('contend', 'exec-1', 'act-2', 1)->waitFor('CLAIMED'), 'another activity must not wait for this one');
    }

    #[Test]
    public function aClaimIsFreeWithinTwoSecondsOfTheHolderBeingKilled(): void
    {
        $holder = self::claimant('hold', 'exec-2', 'act-1', 1);
        self::assertNotNull($holder->waitFor('HELD'));
        self::assertNotNull(self::claimant('contend', 'exec-2', 'act-1', 1)->waitFor('DEFERRED'));

        $killedAt = $holder->kill9();
        $claimedAt = null;
        for ($until = microtime(true) + 5.0; null === $claimedAt && microtime(true) < $until; usleep(10_000)) {
            $claimedAt = self::claimant('contend', 'exec-2', 'act-1', 1)->waitFor('CLAIMED', 2);
        }

        self::assertNotNull($claimedAt);
        self::assertLessThan(2_000, (float) ((int) $claimedAt[1] - $killedAt) / 1e6);
    }

    #[Test]
    public function aReleasedClaimIsTakenAtOnce(): void
    {
        self::assertNotNull(self::claimant('release', 'exec-3', 'act-1', 1)->waitFor('RELEASED'));

        self::assertNotNull(self::claimant('contend', 'exec-3', 'act-1', 1)->waitFor('CLAIMED'));
    }

    #[Test]
    public function aHolderWhoseConnectionWasKilledFindsOutBeforeItActs(): void
    {
        $holder = self::claimant('watch', 'exec-4', 'act-1', 1);
        $held = $holder->waitFor('HELD');
        self::assertNotNull($held);

        (new Harness(['getlock', 'setup', 'unused']))->pdo()->exec('KILL ' . (int) $held[2]);

        self::assertNotNull($holder->waitFor('LOST', 5), 'the adapter reconnected silently and holds() said yes');
        self::assertNotNull(self::claimant('contend', 'exec-4', 'act-1', 1)->waitFor('CLAIMED'));
    }

    private static function claimant(string $mode, string $execution, string $activity, int $attempt): Harness
    {
        return new Harness([$mode, $execution, $activity, (string) $attempt], 'claim-worker.php');
    }
}
