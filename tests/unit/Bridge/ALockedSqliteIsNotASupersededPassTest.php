<?php

declare(strict_types=1);

namespace unit\Bridge;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema as DbalSchema;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Bridge\Illuminate\Schema\DurableSchema as IlluminateSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateEventStore;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Exception\SupersededPassException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\FencedEventStoreInterface;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #616, found by sirius: on SQLite, a database locked by an unrelated writer is a transient wait,
 * not a newer pass. A pass whose fence is still current must see the lock, not a supersession
 * that would make its handler stop with nobody else owning the run.
 */
final class ALockedSqliteIsNotASupersededPassTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/durable-locked-' . bin2hex(random_bytes(4)) . '.sqlite';
        touch($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /** @return iterable<string, array{string}> */
    public static function stores(): iterable
    {
        yield 'DBAL' => ['dbal'];
        yield 'Illuminate' => ['illuminate'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function storesAndLocks(): iterable
    {
        foreach (['DBAL' => 'dbal', 'Illuminate' => 'illuminate'] as $name => $bridge) {
            // IMMEDIATE lets the head be read back; EXCLUSIVE blocks that read too.
            yield $name . ', IMMEDIATE' => [$bridge, 'IMMEDIATE'];
            yield $name . ', EXCLUSIVE' => [$bridge, 'EXCLUSIVE'];
        }
    }

    #[DataProvider('storesAndLocks')]
    public function testACurrentPassBlockedByAnotherWriterIsNotSuperseded(string $bridge, string $lock): void
    {
        $store = $this->store($bridge);
        $fence = $store->claimPass(ExecutionId::fromString('exec-1'));

        $other = new \PDO('sqlite:' . $this->file);
        $other->exec('BEGIN ' . $lock); // an unrelated writer holds the database

        try {
            $store->appendFenced(new TimerCompleted(ExecutionId::fromString('exec-1'), 'timer-1'), $fence);
            self::fail('the database is locked');
        } catch (SupersededPassException) {
            self::fail('a lock is not a newer pass: the fence is still current');
        } catch (LockWaitTimeoutException|QueryException $e) {
            self::assertStringContainsString('database is locked', $e->getMessage());
        } finally {
            $other->exec('ROLLBACK');
        }
    }

    /** The lost race DUR053 names: blocked, and a newer pass has claimed meanwhile. */
    #[DataProvider('stores')]
    public function testAStalePassBlockedByALockIsSuperseded(string $bridge): void
    {
        $store = $this->store($bridge);
        $older = $store->claimPass(ExecutionId::fromString('exec-1'));
        $store->claimPass(ExecutionId::fromString('exec-1'));

        $other = new \PDO('sqlite:' . $this->file);
        $other->exec('BEGIN IMMEDIATE');

        try {
            $this->expectException(SupersededPassException::class);
            $store->appendFenced(new TimerCompleted(ExecutionId::fromString('exec-1'), 'timer-1'), $older);
        } finally {
            $other->exec('ROLLBACK');
        }
    }

    private function store(string $bridge): FencedEventStoreInterface
    {
        if ('dbal' === $bridge) {
            $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->file, 'driverOptions' => [\PDO::ATTR_TIMEOUT => 1]]);

            return new DbalEventStore($connection, new DbalSchema($connection));
        }
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => $this->file, 'prefix' => '', 'busy_timeout' => 1000]);
        $connection = $capsule->getConnection();

        return new IlluminateEventStore($connection, new IlluminateSchema($connection));
    }
}
