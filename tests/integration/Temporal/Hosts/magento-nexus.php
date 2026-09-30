<?php

declare(strict_types=1);

/**
 * A Magento module's Nexus serving, driven through RuntimeFactory without Mage-OS (#668): the
 * factory as di.xml would build it, `nexusHandlers` and `workflowClasses` included.
 *
 * Usage: php magento-nexus.php <dsn> <nexus|journal>
 *
 * Two processes, one role each, as `bin/magento durable:worker --role=…` runs them: the Nexus
 * worker answers `billing/verify` and starts the workflow that fulfils `billing/charge`, which the
 * journal worker then runs.
 */

use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use unit\DurableModule\Fixture\NexusBillingHandler;
use unit\DurableModule\Fixture\NexusChargeWorkflow;

require __DIR__ . '/../../../../vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
[, $dsn, $role] = $arguments + [null, '', ''];

$factory = new RuntimeFactory(
    workflowClasses: [NexusChargeWorkflow::class],
    temporalDsn: (string) $dsn,
    nexusHandlers: [new NexusBillingHandler()],
);
$tick = 'nexus' === $role ? $factory->nexusWorker()->pollOnce(...) : $factory->journalWorker()->processOne(...);

$deadline = microtime(true) + (float) (getenv('DURABLE_HOST_WORKER_MAX_TIME') ?: 180);
while (microtime(true) < $deadline) {
    try {
        $tick();
    } catch (Throwable $e) {
        fwrite(STDERR, "magento {$role} worker: " . $e::class . ': ' . $e->getMessage() . "\n");
        sleep(1);
    }
}
