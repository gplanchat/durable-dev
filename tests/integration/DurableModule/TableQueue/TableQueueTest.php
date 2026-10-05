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
        $afterSeconds = (float) ((int) $again[1] - (int) $taken[1]) / 1e9;
        self::assertGreaterThan(3.0, $afterSeconds, 'delivered again while the lease held');
        self::assertLessThan(5.5, $afterSeconds);
        self::assertSame('EMPTY', $events[0][0], 'the second worker saw the message while the lease held');
    }

    #[Test]
    public function aDelayedMessageIsNotTakenBeforeItsAvailableAt(): void
    {
        Harness::run(['enqueue', 'q', 'later', '3']);
        $availableAt = (int) $this->pdo->query('SELECT UNIX_TIMESTAMP(available_at) FROM durable_queue')->fetchColumn();

        $events = Harness::run(['poll', 'q', '60', '30', '8']);

        self::assertSame('EMPTY', $events[0][0], 'taken at once');
        $taken = array_values(array_filter($events, static fn(array $e): bool => 'TAKEN' === $e[0]))[0] ?? null;
        self::assertNotNull($taken, 'never taken');
        self::assertGreaterThanOrEqual($availableAt, (int) $taken[3], 'taken before available_at');
    }

    #[Test]
    public function twoWorkersNeverTakeTheSameMessage(): void
    {
        for ($i = 0; $i < 60; ++$i) {
            $this->pdo->exec(\sprintf("INSERT INTO durable_queue (queue_name, body, available_at) VALUES ('q', 'm%d', NOW())", $i));
        }

        $workers = [new Harness(['drain', 'q', '60', '30']), new Harness(['drain', 'q', '60', '30'])];
        $ids = [];
        $counts = [];
        foreach ($workers as $worker) {
            $mine = array_column($worker->events(), 2);
            $counts[] = \count($mine);
            $ids = [...$ids, ...$mine];
        }

        self::assertCount(60, $ids);
        self::assertCount(60, array_unique($ids), 'a message was taken twice');
        self::assertGreaterThan(0, min($counts), 'one worker took everything: the take never contended');
    }
}
