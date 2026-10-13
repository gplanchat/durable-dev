<?php

declare(strict_types=1);

namespace integration\DurableModule\TableQueue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The table queue (#731) against a real MySQL, with Magento's adapter and real processes. Not run
 * in CI yet (#738): `DURABLE_TEST_MYSQL` and `magento/vendor` are missing there, and the tests skip.
 *
 * @internal
 */
final class TableQueueTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        if (null !== $reason = Harness::unavailable()) {
            self::markTestSkipped($reason);
        }
        Harness::run(['setup']);
        $this->pdo = (new Harness([]))->pdo();
        $this->pdo->exec('DELETE FROM durable_queue');
    }

    #[Test]
    public function aMessageIsEnqueuedTakenAndAcknowledged(): void
    {
        Harness::run(['enqueue', 'q', 'hello', '0']);

        $events = Harness::run(['drain', 'q', '60', '30']);

        self::assertSame(['ACKED'], array_column($events, 0));
        self::assertSame('0', (string) $this->pdo->query('SELECT COUNT(*) FROM durable_queue')->fetchColumn());
    }

    #[Test]
    public function aTakenUnacknowledgedMessageComesBackAfterItsLeaseAndNotBefore(): void
    {
        Harness::run(['enqueue', 'q', 'hello', '0']);
        $first = new Harness(['take', 'q', '3', '1', 'hold']);
        $taken = $first->waitForTaken();
        self::assertNotNull($taken);

        $second = new Harness(['poll', 'q', '3', '1', '8']);
        $events = $second->events();

        $again = array_values(array_filter($events, static fn(array $e): bool => 'TAKEN' === $e[0]))[0] ?? null;
        self::assertNotNull($again, 'the message never came back');
        self::assertSame($taken[2], $again[2], 'the same message');
        // The database clock, read by each worker after its take: `$taken[4]` is the end of the lease the first worker holds.
        self::assertGreaterThanOrEqual((float) $taken[4], (float) $again[3], 'delivered again while the lease held');
        $afterSeconds = (float) ((int) $again[1] - (int) $taken[1]) / 1e9;
        self::assertLessThan(5.5, $afterSeconds);
        self::assertSame('EMPTY', $events[0][0], 'the second worker saw the message while the lease held');
    }

    #[Test]
    public function aDelayedMessageIsNotTakenBeforeItsAvailableAt(): void
    {
        Harness::run(['enqueue', 'q', 'later', '3']);
        $availableAt = self::milliseconds((string) $this->pdo->query('SELECT UNIX_TIMESTAMP(available_at) FROM durable_queue')->fetchColumn());

        $events = Harness::run(['poll', 'q', '60', '30', '8']);

        self::assertSame('EMPTY', $events[0][0], 'taken at once');
        $taken = array_values(array_filter($events, static fn(array $e): bool => 'TAKEN' === $e[0]))[0] ?? null;
        self::assertNotNull($taken, 'never taken');
        self::assertGreaterThanOrEqual($availableAt, self::milliseconds($taken[3]), 'taken before available_at');
    }

    #[Test]
    public function twoWorkersNeverTakeTheSameMessage(): void
    {
        for ($i = 0; $i < 60; ++$i) {
            $this->pdo->exec(\sprintf("INSERT INTO durable_queue (queue_name, body, available_at) VALUES ('q', 'm%d', NOW())", $i));
        }

        // A worker that starts early drains the 60 messages alone. Both wait on a barrier before the first take, then
        // on a second one until each holds a message: neither acknowledges before the other has taken, so both contend.
        $barrier = sys_get_temp_dir() . '/' . uniqid('durable-barrier-', true);
        mkdir($barrier);
        $workers = [new Harness(['drain', 'q', '60', '30', $barrier, '2']), new Harness(['drain', 'q', '60', '30', $barrier, '2'])];
        $ids = [];
        $counts = [];
        $report = [];
        foreach ($workers as $number => $worker) {
            $mine = array_column($worker->events(), 2);
            $counts[] = \count($mine);
            $report[] = \sprintf('worker %d acknowledged %d, %s', $number, \count($mine), $worker->describe());
            $ids = [...$ids, ...$mine];
        }
        array_map('unlink', glob($barrier . '/*') ?: []);
        rmdir($barrier);

        $report = implode("\n", $report);
        self::assertCount(60, $ids, $report);
        self::assertCount(60, array_unique($ids), "a message was taken twice\n" . $report);
        self::assertGreaterThan(0, min($counts), "one worker took everything: the take never contended\n" . $report);
    }

    #[Test]
    public function anAckAfterTheLeaseRanOutDoesNotDeleteTheRedeliveredCopy(): void
    {
        Harness::run(['enqueue', 'q', 'hello', '0']);

        $events = Harness::run(['stale', 'q', '2', '1']);

        self::assertSame(['STALE_ACK', 'refused'], [$events[0][0], $events[0][2]]);
        self::assertSame(['FRESH_ACK', 'deleted'], [$events[1][0], $events[1][2]]);
    }

    #[Test]
    public function aMessageDelayedByHalfASecondIsTakenAfterHalfASecondAndWithinASecond(): void
    {
        $enqueued = Harness::run(['enqueue', 'q', 'soon', '0.5'])[0];
        $availableAt = self::milliseconds((string) $this->pdo->query('SELECT UNIX_TIMESTAMP(available_at) FROM durable_queue')->fetchColumn());

        $events = Harness::run(['poll', 'q', '60', '30', '5']);

        $taken = array_values(array_filter($events, static fn(array $e): bool => 'TAKEN' === $e[0]))[0] ?? null;
        self::assertNotNull($taken, 'never taken');
        // Database clock only: `$enqueued[2]` and `$enqueued[3]` bracket the insert, `$taken[3]` is read after the take.
        // Whether the poller started before or after the delay ran out depends on the runner, so the first answer is not asserted.
        self::assertGreaterThanOrEqual(self::milliseconds($enqueued[2]) + 500, $availableAt, 'a 0.5 s delay fired early');
        self::assertLessThanOrEqual(self::milliseconds($enqueued[3]) + 500, $availableAt, 'a 0.5 s delay was rounded up');
        self::assertGreaterThanOrEqual($availableAt, self::milliseconds($taken[3]), 'taken before available_at');
        self::assertLessThan($availableAt + 1_000, self::milliseconds($taken[3]), 'taken a second after available_at');
    }

    /** A `UNIX_TIMESTAMP` with three decimals, as whole milliseconds: no float rounding between two readings. */
    private static function milliseconds(string $timestamp): int
    {
        return (int) round((float) $timestamp * 1000);
    }
}
