<?php

declare(strict_types=1);

/*
 * One resume-lock worker, run by the tests and by measure.php as its own process.
 *
 *   php worker.php <getlock|ttl:SECONDS> <hold|watch|contend|race> <key> [argument]
 *
 * It prints one line per event, with `hrtime(true)` in nanoseconds: the monotonic clock is shared
 * by every process of the machine, so a parent and its workers compare their stamps directly.
 */

use Gplanchat\DurableModule\Runtime\ResumeLock\GetLockResumeLock;
use Gplanchat\DurableModule\Runtime\ResumeLock\ResumeLock;
use Gplanchat\DurableModule\Runtime\ResumeLock\TtlRowResumeLock;

require __DIR__ . '/bootstrap.php';

[, $kind, $mode, $key] = $argv;
$argument = (int) ($argv[4] ?? 0);
$connection = durable_test_connection();
$lock = 'getlock' === $kind
    ? new GetLockResumeLock($connection)
    : new TtlRowResumeLock($connection, (int) substr($kind, 4));

function say(string $event, int|string ...$values): void
{
    echo implode(' ', [$event, hrtime(true), ...$values]), "\n";
    flush();
}

function acquire(ResumeLock $lock, string $key, float $seconds): bool
{
    for ($until = microtime(true) + $seconds; microtime(true) < $until; usleep(10_000)) {
        if ($lock->tryAcquire($key)) {
            return true;
        }
    }

    return false;
}

switch ($mode) {
    case 'setup':
        $connection->dropTable('durable_resume_lock');
        $connection->createTable(TtlRowResumeLock::table($connection));
        break;
    case 'hold':
    case 'watch':
        $lock->tryAcquire($key) || exit(1);
        say('HELD', (int) $connection->fetchOne('SELECT CONNECTION_ID()'));
        // `hold` waits for a signal, `watch` reports the moment the lock is gone.
        while ('hold' === $mode || $lock->holds($key)) {
            usleep(20_000);
        }
        say('LOST');
        break;
    case 'contend':
        if ($lock->tryAcquire($key)) {
            say('ACQUIRED');
            break;
        }
        say('BLOCKED');
        acquire($lock, $key, (float) $argument) ? say('ACQUIRED') : say('TIMEOUT');
        break;
    case 'race':
        for ($i = 0; $i < $argument; ++$i) {
            acquire($lock, $key, 30) || exit(1);
            $enter = hrtime(true);
            usleep(random_int(1_000, 4_000));
            $leave = hrtime(true);
            $lock->release($key);
            say('SLICE', $enter, $leave);
        }
        break;
}
