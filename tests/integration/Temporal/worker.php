<?php

declare(strict_types=1);

/**
 * Integration worker: one process, one queue, one role (workflow or activity).
 *
 * Both roles long-poll for several tens of seconds; alternating them inside a single process
 * amounts to starving one while the other waits. As in production, they therefore run separately.
 *
 * Usage: php worker.php <address> <namespace> <taskQueue> <workflow|activity> [transport]
 */

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceActivityRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Gplanchat\Durable\WorkflowRegistry;
use integration\Temporal\Fixtures\IntegrationWorkflows;

require __DIR__ . '/../../../vendor/autoload.php';

[$address, $namespace, $taskQueue, $role] = [$argv[1], $argv[2], $argv[3], $argv[4]];
$transport = $argv[5] ?? TemporalConnection::TRANSPORT_GRPC;

$connection = new TemporalConnection(
    target: $address,
    namespace: $namespace,
    identity: 'durable-it-' . $role,
    workflowTaskQueue: $taskQueue,
    activityTaskQueue: $taskQueue,
    transport: $transport,
);
$client = WorkflowServiceClientFactory::create($connection);

if ('workflow' === $role) {
    $registry = new WorkflowRegistry();
    IntegrationWorkflows::registerWorkflows($registry);
    // The same workflow type, two bodies: that is what a deployment does to an in-flight
    // execution, and the only way to observe the divergence guard against a real server.
    IntegrationWorkflows::registerDivergentPair($registry, getenv('DURABLE_WORKER_VARIANT') ?: 'default');

    $processor = new WorkflowTaskProcessor(
        $client,
        $connection,
        new WorkflowTaskRunner(new TemporalHistoryCursor($client, $connection), $registry, $connection),
    );

    while (true) {
        try {
            $processor->processOne();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'workflow worker: ' . $e::class . ': ' . $e->getMessage() . "\n");
        }
    }
}

$executor = new RegistryActivityExecutor();
IntegrationWorkflows::registerActivities($executor);
$journal = new InMemoryEventStore();

$worker = new TemporalActivityWorker(
    new WorkflowServiceActivityRpc($client),
    $connection,
    new ActivityMessageProcessor(
        $journal,
        new NoopActivityTransport(),
        $executor,
        new NullWorkflowResumeDispatcher(),
        new NullActivityHeartbeatSender(),
    ),
    $journal,
    new NullActivityHeartbeatSender(),
);

while (true) {
    try {
        $worker->pollOnce();
    } catch (\Throwable $e) {
        fwrite(STDERR, 'activity worker: ' . $e::class . ': ' . $e->getMessage() . "\n");
    }
}
