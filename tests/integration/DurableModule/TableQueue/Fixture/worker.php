<?php

declare(strict_types=1);

/*
 * One table-queue worker, run by the tests as its own process.
 *
 *   php worker.php setup
 *   php worker.php enqueue <queue> <body> <delay>
 *   php worker.php take    <queue> <lease> <claim> <hold|exit>   take one message, keep or drop the process
 *   php worker.php poll    <queue> <lease> <claim> <seconds>     take until one comes, print what it saw
 *   php worker.php stale   <queue> <lease> <claim>               take, let the lease run out, take again, then ack both copies
 *   php worker.php drain   <queue> <lease> <claim> [<dir> <n>]   take and ack until the queue answers empty; with a
 *                                                                barrier, connect, wait until n workers did, take one
 *                                                                message, then wait until n workers hold one
 *
 * It prints one line per event, with `hrtime(true)` in nanoseconds: the monotonic clock is shared
 * by every process of the machine.
 */

use Gplanchat\DurableModule\Runtime\TableQueue\TableQueue;
use Gplanchat\DurableModule\Schema\JournalSchema;

require __DIR__ . '/bootstrap.php';

/** Writes this process's marker for `$stage`, then waits until `$workers` markers exist. */
function rendezvous(string $directory, string $stage, int $workers): void
{
    touch($directory . '/' . $stage . '.' . getmypid());
    for ($until = microtime(true) + 60; \count(glob($directory . '/' . $stage . '.*') ?: []) < $workers; usleep(1_000)) {
        if (microtime(true) > $until) {
            fwrite(\STDERR, sprintf("rendezvous '%s': %d of %d workers after 60 s\n", $stage, \count(glob($directory . '/' . $stage . '.*') ?: []), $workers));
            exit(1);
        }
    }
}

function say(string $event, int|string ...$values): void
{
    echo implode(' ', [$event, hrtime(true), ...$values]), "\n";
    flush();
}

$mode = $argv[1];
$connection = durable_test_connection();
if ('setup' === $mode) {
    (new JournalSchema($connection))->setup();
    say('DONE');
    exit;
}

$queue = $argv[2];
if ('enqueue' === $mode) {
    (new TableQueue($connection, 2, 1))->enqueue($queue, $argv[3], (float) $argv[4]);
    say('ENQUEUED');
    exit;
}

$tableQueue = new TableQueue($connection, (int) $argv[3], (int) $argv[4]);
switch ($mode) {
    case 'take':
        $message = $tableQueue->take($queue) ?? exit(1);
        // Both times come from the database clock: the lease is measured against it, not against this machine's speed.
        say('TAKEN', $message->id, $connection->fetchOne('SELECT UNIX_TIMESTAMP(NOW(3))'), $connection->fetchOne('SELECT UNIX_TIMESTAMP(leased_until) FROM durable_queue WHERE id = ?', [$message->id]));
        while ('hold' === $argv[5]) {
            sleep(1);
        }
        break;
    case 'poll':
        for ($until = microtime(true) + (float) $argv[5]; microtime(true) < $until; usleep(100_000)) {
            if (null !== $message = $tableQueue->take($queue)) {
                say('TAKEN', $message->id, $connection->fetchOne('SELECT UNIX_TIMESTAMP(NOW(3))'));
                exit;
            }
            say('EMPTY');
        }
        say('TIMEOUT');
        break;
    case 'stale':
        $first = $tableQueue->take($queue) ?? exit(1);
        $second = null;
        while (null === $second) {
            sleep(1);
            $second = (new TableQueue($connection, (int) $argv[3], (int) $argv[4]))->take($queue);
        }
        say('STALE_ACK', $tableQueue->ack($first) ? 'deleted' : 'refused');
        say('FRESH_ACK', $tableQueue->ack($second) ? 'deleted' : 'refused');
        break;
    case 'drain':
        $barrier = isset($argv[5], $argv[6]);
        if ($barrier) {
            // Connect first, then release together: no worker drains while the other is still starting.
            $connection->fetchOne('SELECT 1');
            rendezvous($argv[5], 'ready', (int) $argv[6]);
        }
        $message = $tableQueue->take($queue);
        if ($barrier) {
            // A take can answer empty while the other worker's transaction still holds the rows it scans: ask again for
            // a moment. Whatever happens, write the marker, so the other worker never waits for one that is not coming.
            for ($until = microtime(true) + 10; null === $message && microtime(true) < $until; usleep(10_000)) {
                $message = $tableQueue->take($queue);
            }
            if (null === $message) {
                fwrite(\STDERR, "the first take answered empty for 10 s while 60 messages were due\n");
            }
            // Nobody acknowledges before every worker holds a message: each one has taken at least one, whatever its speed.
            rendezvous($argv[5], 'taken', (int) $argv[6]);
        }
        while (null !== $message) {
            usleep(random_int(5_000, 30_000));
            $tableQueue->ack($message);
            say('ACKED', $message->id);
            $message = $tableQueue->take($queue);
        }
        break;
}
