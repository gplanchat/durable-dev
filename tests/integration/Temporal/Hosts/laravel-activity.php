<?php

declare(strict_types=1);

/*
 * The Temporal activity worker as a Laravel application gets it: `DurableServiceProvider` on a bare
 * container with `backend: temporal`, the activities resolved from that container, so the sender
 * they inject is the provider's (#510, #518). The loop is `durable:temporal-worker --role=activity`
 * with `--max-time`: illuminate/console is not a root dependency, so the command itself cannot run.
 *
 * DURABLE_HEARTBEAT=noop hands the activities the no-op sender instead, to show the heartbeat test
 * red. Only this script reads it: nothing under src/ knows it.
 *
 * Required by worker.php, whose arguments it reads: <address> <namespace> <taskQueue> <role> [transport].
 */

use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\Durable\Activity\PayloadToContractMethodInvoker;
use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\RegistryActivityExecutor;
use Illuminate\Container\Container;
use integration\Temporal\Fixtures\HeartbeatActivities;
use integration\Temporal\Fixtures\HeartbeatingActivities;

$arguments = $_SERVER['argv'] ?? null;
if (!\is_array($arguments) || !isset($arguments[1], $arguments[2], $arguments[3])) {
    fwrite(\STDERR, "Usage: worker.php <address> <namespace> <taskQueue> <role> [transport]\n");

    exit(1);
}
[$address, $namespace, $taskQueue] = [$arguments[1], $arguments[2], $arguments[3]];
$transport = $arguments[5] ?? 'auto';

$dsn = \sprintf(
    'temporal://%s?namespace=%s&journal_task_queue=%3$s&workflow_task_queue=%3$s&activity_task_queue=%3$s&transport=%4$s',
    $address,
    rawurlencode($namespace),
    rawurlencode($taskQueue),
    rawurlencode($transport),
);

$app = new Container();
$app->instance('config', new ArrayObject(['durable' => ['backend' => 'temporal', 'temporal' => ['dsn' => $dsn]]], ArrayObject::ARRAY_AS_PROPS));
(new DurableServiceProvider($app))->register();

if ('noop' === getenv('DURABLE_HEARTBEAT')) {
    $app->instance(ActivityHeartbeatSenderInterface::class, new NullActivityHeartbeatSender());
}
$activities = $app->make(HeartbeatingActivities::class);
$executor = $app->make(RegistryActivityExecutor::class);
foreach ((new ReflectionClass(HeartbeatActivities::class))->getMethods() as $method) {
    $name = $method->getAttributes(Gplanchat\Durable\Attribute\AsActivityMethod::class)[0]->newInstance()->name;
    $executor->register($name, new PayloadToContractMethodInvoker($activities, HeartbeatActivities::class, $method->getName()));
}

$worker = $app->make(TemporalActivityWorker::class);
$deadline = microtime(true) + (float) (getenv('DURABLE_HOST_WORKER_MAX_TIME') ?: 180);
while (microtime(true) < $deadline) {
    try {
        $worker->pollOnce();
    } catch (Throwable $e) {
        fwrite(STDERR, 'laravel activity worker: ' . $e::class . ': ' . $e->getMessage() . "\n");
    }
}
