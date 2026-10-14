<?php

declare(strict_types=1);

/*
 * The database worker as its own process: ticks until the stop file exists, then prints how many
 * messages it handled. The deadline only bounds a test that never touched the stop file.
 *
 *   php worker.php <stop file>
 */

use Gplanchat\DurableModule\Runtime\DatabaseWorker;

require __DIR__ . '/backend.php';

$stop = $argv[1];
$worker = new DatabaseWorker(durable_client_backend(), null, 0.05);
echo "READY\n";
flush();

$handled = 0;
for ($deadline = microtime(true) + 120; !is_file($stop) && microtime(true) < $deadline;) {
    $worker->tick() ? ++$handled : usleep(20_000);
}
echo 'HANDLED ', $handled, "\n";
