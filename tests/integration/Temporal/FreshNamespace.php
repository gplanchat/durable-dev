<?php

declare(strict_types=1);

namespace integration\Temporal;

use Google\Protobuf\Duration;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Temporal\Api\Workflowservice\V1\DescribeNamespaceRequest;
use Temporal\Api\Workflowservice\V1\DescribeNamespaceResponse;
use Temporal\Api\Workflowservice\V1\RegisterNamespaceRequest;
use Temporal\Api\Workflowservice\V1\RegisterNamespaceResponse;

/**
 * A namespace of its own for each test: the conformance suites assert exact sets ("an empty catalog
 * lists nothing", "the stream of exec-order") and reuse their execution ids from one run to the
 * next, which a namespace shared with the rest of the integration suite cannot honour.
 *
 * The client interface has no namespace RPC — the bridge never manages namespaces — so this goes
 * through the transport directly.
 */
trait FreshNamespace
{
    private const NAMESPACE_READY_TIMEOUT_SECONDS = 30.0;

    private static function freshNamespaceConnection(): TemporalConnection
    {
        $address = getenv('DURABLE_TEMPORAL_ADDRESS');
        if (false === $address || '' === $address) {
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS not set: no Temporal server.');
        }
        $transport = TemporalServerTestCase::transportFromEnv();
        if (TemporalConnection::TRANSPORT_GRPC === $transport && !\extension_loaded('grpc')) {
            self::markTestSkipped('ext-grpc is not loaded; set DURABLE_TEMPORAL_TRANSPORT=grpc-curl to run without it.');
        }

        // ponytail: the namespaces are never deleted; a dev server is thrown away with them.
        $namespace = 'durable-conformance-' . bin2hex(random_bytes(6));
        $queue = 'durable-conformance-' . bin2hex(random_bytes(6));
        $connection = new TemporalConnection(
            target: $address,
            namespace: $namespace,
            journalTaskQueue: $queue,
            identity: 'durable-conformance',
            workflowTaskQueue: $queue,
            activityTaskQueue: $queue,
            transport: $transport,
        );

        $transportClient = WorkflowServiceClientFactory::createTransport($connection);
        $service = '/temporal.api.workflowservice.v1.WorkflowService/';
        $transportClient->unary($service . 'RegisterNamespace', new RegisterNamespaceRequest([
            'namespace' => $namespace,
            'workflow_execution_retention_period' => new Duration(['seconds' => 86_400]),
        ]), RegisterNamespaceResponse::class, [], 10_000);

        // A registration propagates asynchronously on a server with a namespace cache.
        $deadline = microtime(true) + self::NAMESPACE_READY_TIMEOUT_SECONDS;
        while (true) {
            try {
                $transportClient->unary($service . 'DescribeNamespace', new DescribeNamespaceRequest(['namespace' => $namespace]), DescribeNamespaceResponse::class, [], 5_000);

                return $connection;
            } catch (\RuntimeException $notYet) {
                if (microtime(true) > $deadline) {
                    self::fail(\sprintf('Namespace "%s" still unknown %.0f s after its registration: %s', $namespace, self::NAMESPACE_READY_TIMEOUT_SECONDS, $notYet->getMessage()));
                }
                usleep(200_000);
            }
        }
    }
}
