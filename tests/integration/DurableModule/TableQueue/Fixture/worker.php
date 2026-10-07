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
 *   php worker.php drain   <queue> <lease> <claim>               take and ack until the queue answers empty
 *
 * It prints one line per event, with `hrtime(true)` in nanoseconds: the monotonic clock is shared
 * by every process of the machine.
 */

use Gplanchat\DurableModule\Runtime\TableQueue\TableQueue;
use Gplanchat\DurableModule\Schema\JournalSchema;

require __DIR__ . '/bootstrap.php';

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
        say('TAKEN', $message->id);
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
        while (null !== $message = $tableQueue->take($queue)) {
            usleep(random_int(5_000, 30_000));
            $tableQueue->ack($message);
            say('ACKED', $message->id);
        }
        break;
}
