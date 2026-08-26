<?php

declare(strict_types=1);

/**
 * Worker d'intégration : un processus, une file, un rôle (workflow ou activité).
 *
 * Les deux rôles font des long-polls de plusieurs dizaines de secondes ; les alterner dans un
 * seul processus revient à affamer l'un pendant que l'autre attend. Comme en production, ils
 * tournent donc séparément.
 *
 * Usage : php worker.php <address> <namespace> <taskQueue> <workflow|activity>
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

require __DIR__.'/../../../vendor/autoload.php';

[$address, $namespace, $taskQueue, $role] = [$argv[1], $argv[2], $argv[3], $argv[4]];

$connection = new TemporalConnection(
    target: $address,
    namespace: $namespace,
    identity: 'durable-it-'.$role,
    workflowTaskQueue: $taskQueue,
    activityTaskQueue: $taskQueue,
);
$client = WorkflowServiceClientFactory::create($connection);

if ('workflow' === $role) {
    $registry = new WorkflowRegistry();
    IntegrationWorkflows::registerWorkflows($registry);

    $processor = new WorkflowTaskProcessor(
        $client,
        $connection,
        new WorkflowTaskRunner(new TemporalHistoryCursor($client, $connection), $registry, $connection),
    );

    while (true) {
        try {
            $processor->processOne();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'workflow worker: '.$e::class.': '.$e->getMessage()."\n");
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
        fwrite(STDERR, 'activity worker: '.$e::class.': '.$e->getMessage()."\n");
    }
}
