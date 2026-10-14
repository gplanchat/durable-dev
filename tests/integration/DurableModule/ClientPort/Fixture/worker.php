<?php

declare(strict_types=1);

/*
 * The database worker as its own process: ticks until the stop file exists, then prints how many
 * messages it handled. The deadline only bounds a test that never touched the stop file.
 *
 *   php worker.php <stop file>
 */

use Gplanchat\DurableModule\Runtime\DatabaseWorker;
use Psr\Log\AbstractLogger;

require __DIR__ . '/backend.php';

$stop = $argv[1];
// What the worker logs goes to stdout, where the test reads it when an assertion fails.
$logger = new class extends AbstractLogger {
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $cause = ($context['exception'] ?? null) instanceof Throwable ? ' ' . $context['exception']::class . ': ' . $context['exception']->getMessage() : '';
        echo 'LOG ', $level, ' ', $message, $cause, "\n";
    }
};
$worker = new DatabaseWorker(durable_client_backend(), $logger, 0.05);
echo "READY\n";
flush();

$handled = 0;
for ($deadline = microtime(true) + 120; !is_file($stop) && microtime(true) < $deadline;) {
    $worker->tick() ? ++$handled : usleep(20_000);
}
echo 'HANDLED ', $handled, "\n";
