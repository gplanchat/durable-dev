<?php

declare(strict_types=1);

/*
 * The real `durable:worker` command, as its own process, on the bench's factory. Usage:
 * worker.php [--role=...] [--time-limit=...] [--max-tasks=...]
 */

use Gplanchat\Durable\MagentoBench\BenchRuntime;
use Gplanchat\DurableModule\Console\Command\RunWorkerCommand;
use Symfony\Component\Console\Application;

require __DIR__ . '/bootstrap.php';

$application = new Application();
$application->add(new RunWorkerCommand(BenchRuntime::factory()));
$application->setDefaultCommand('durable:worker', true);
$application->setAutoExit(false);

exit($application->run());
