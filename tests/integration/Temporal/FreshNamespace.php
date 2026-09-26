<?php

declare(strict_types=1);

namespace integration\Temporal;

use Google\Protobuf\Duration;
use Gplanchat\Bridge\Temporal\DurableSearchAttributes;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Temporal\Api\Enums\V1\IndexedValueType;
use Temporal\Api\Operatorservice\V1\AddSearchAttributesRequest;
use Temporal\Api\Operatorservice\V1\AddSearchAttributesResponse;
use Temporal\Api\Workflowservice\V1\DescribeNamespaceRequest;
use Temporal\Api\Workflowservice\V1\DescribeNamespaceResponse;
use Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsRequest;
use Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsResponse;
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
        self::awaitNamespace($namespace, 'still unknown', static fn() => $transportClient->unary($service . 'DescribeNamespace', new DescribeNamespaceRequest(['namespace' => $namespace]), DescribeNamespaceResponse::class, [], 5_000));

        // Durable writes these on every start, and a server refuses a start that names an
        // attribute the namespace has no mapping for (#558).
        $transportClient->unary('/temporal.api.operatorservice.v1.OperatorService/AddSearchAttributes', new AddSearchAttributesRequest([
            'namespace' => $namespace,
            'search_attributes' => [
                DurableSearchAttributes::WORKFLOW_NAME => IndexedValueType::INDEXED_VALUE_TYPE_KEYWORD,
                DurableSearchAttributes::EXECUTION_ID => IndexedValueType::INDEXED_VALUE_TYPE_KEYWORD,
            ],
        ]), AddSearchAttributesResponse::class, [], 10_000);
        // Listed at once, usable a couple of seconds later: until then a query naming the attribute
        // fails as a start would, without starting anything.
        self::awaitNamespace($namespace, 'has no usable Durable search attributes', static fn() => $transportClient->unary($service . 'ListWorkflowExecutions', new ListWorkflowExecutionsRequest([
            'namespace' => $namespace,
            'page_size' => 1,
            'query' => DurableSearchAttributes::EXECUTION_ID . " = 'probe' AND " . DurableSearchAttributes::WORKFLOW_NAME . " = 'probe'",
        ]), ListWorkflowExecutionsResponse::class, [], 5_000));

        return $connection;
    }

    private static function awaitNamespace(string $namespace, string $state, callable $probe): void
    {
        $deadline = microtime(true) + self::NAMESPACE_READY_TIMEOUT_SECONDS;
        while (true) {
            try {
                $probe();

                return;
            } catch (\RuntimeException $notYet) {
                if (microtime(true) > $deadline) {
                    self::fail(\sprintf('Namespace "%s" %s %.0f s after its registration: %s', $namespace, $state, self::NAMESPACE_READY_TIMEOUT_SECONDS, $notYet->getMessage()));
                }
                usleep(200_000);
            }
        }
    }
}
