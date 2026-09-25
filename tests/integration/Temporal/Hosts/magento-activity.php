<?php

declare(strict_types=1);

/*
 * The Temporal activity worker as the Magento module gets it: `RuntimeFactory::activityWorker()`,
 * with the shared sender `di.xml` hands the activities (#510, #518). The factory is plain PHP, so
 * it runs here without a Mage-OS install; what Magento adds around it is the ObjectManager and the
 * `bin/magento` command that turns this loop.
 *
 * DURABLE_HEARTBEAT=noop hands the activities the no-op sender instead, to show the heartbeat test
 * red. Only this script reads it: nothing under src/ knows it.
 *
 * Required by worker.php, whose arguments it reads: <address> <namespace> <taskQueue> <role> [transport].
 */

use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Gplanchat\DurableModule\Runtime\SharedActivityHeartbeatSender;
use integration\Temporal\Fixtures\HeartbeatingActivities;

[$address, $namespace, $taskQueue] = [$argv[1], $argv[2], $argv[3]];
$transport = $argv[5] ?? 'auto';

$dsn = \sprintf(
    'temporal://%s?namespace=%s&journal_task_queue=%3$s&workflow_task_queue=%3$s&activity_task_queue=%3$s&transport=%4$s',
    $address,
    rawurlencode($namespace),
    rawurlencode($taskQueue),
    rawurlencode($transport),
);

$shared = new SharedActivityHeartbeatSender();
$factory = new RuntimeFactory(
    activityHandlers: [new HeartbeatingActivities('noop' === getenv('DURABLE_HEARTBEAT') ? new NullActivityHeartbeatSender() : $shared)],
    temporalDsn: $dsn,
    heartbeat: $shared,
);

$worker = $factory->activityWorker();
$deadline = microtime(true) + (float) (getenv('DURABLE_HOST_WORKER_MAX_TIME') ?: 180);
while (microtime(true) < $deadline) {
    try {
        $worker->pollOnce();
    } catch (Throwable $e) {
        fwrite(STDERR, 'magento activity worker: ' . $e::class . ': ' . $e->getMessage() . "\n");
    }
}
