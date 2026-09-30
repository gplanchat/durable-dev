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
use Temporal\Api\Operatorservice\V1\ListSearchAttributesRequest;
use Temporal\Api\Operatorservice\V1\ListSearchAttributesResponse;
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

    private static function freshNamespaceConnection(bool $searchAttributes = true): TemporalConnection
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
            // The conformance namespaces register them below, so the suite runs as an enabled host
            // unless it asks otherwise.
            searchAttributes: $searchAttributes,
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
        // Through the same wait: on an older server (1.20) the operator service reads the namespace
        // from a cache that learns of it seconds after DescribeNamespace does. And 1.20 keeps these
        // attributes cluster-wide, so "already exists" (6) is the state wanted, not a failure (#523).
        $wanted = [
            DurableSearchAttributes::WORKFLOW_NAME => IndexedValueType::INDEXED_VALUE_TYPE_KEYWORD,
            DurableSearchAttributes::EXECUTION_ID => IndexedValueType::INDEXED_VALUE_TYPE_KEYWORD,
        ];
        $operator = '/temporal.api.operatorservice.v1.OperatorService/';
        self::awaitNamespace($namespace, 'still unknown to the operator service', static fn() => self::alreadyExistsIsFine(
            static fn() => $transportClient->unary($operator . 'AddSearchAttributes', new AddSearchAttributesRequest([
                'namespace' => $namespace,
                'search_attributes' => $wanted,
            ]), AddSearchAttributesResponse::class, [], 10_000),
            static fn(): ListSearchAttributesResponse => $transportClient->unary($operator . 'ListSearchAttributes', new ListSearchAttributesRequest(['namespace' => $namespace]), ListSearchAttributesResponse::class, [], 10_000),
            $wanted,
        ));
        // Listed at once, usable a couple of seconds later: until then a query naming the attribute
        // fails as a start would, without starting anything.
        self::awaitNamespace($namespace, 'has no usable Durable search attributes', static fn() => $transportClient->unary($service . 'ListWorkflowExecutions', new ListWorkflowExecutionsRequest([
            'namespace' => $namespace,
            'page_size' => 1,
            'query' => DurableSearchAttributes::EXECUTION_ID . " = 'probe' AND " . DurableSearchAttributes::WORKFLOW_NAME . " = 'probe'",
        ]), ListWorkflowExecutionsResponse::class, [], 5_000));

        return $connection;
    }

    /**
     * "Already exists" is the state wanted only if it exists with the wanted type: an attribute
     * registered with another one would fail every query that names it, far from its cause.
     * A LogicException, so awaitNamespace() does not retry it until its timeout.
     *
     * @param callable(): mixed                         $add
     * @param callable(): ListSearchAttributesResponse $list
     * @param array<string, int>                        $wanted name => IndexedValueType
     */
    private static function alreadyExistsIsFine(callable $add, callable $list, array $wanted): void
    {
        try {
            $add();
        } catch (\RuntimeException $failure) {
            if (6 !== $failure->getCode()) {
                throw $failure;
            }
            $registered = [];
            foreach ($list()->getCustomAttributes() as $name => $type) {
                $registered[(string) $name] = (int) $type;
            }
            foreach ($wanted as $name => $type) {
                $actual = $registered[$name] ?? null;
                if ($type !== $actual) {
                    throw new \LogicException(\sprintf('Search attribute %s exists as %s, expected %s.', $name, null === $actual ? 'nothing' : IndexedValueType::name($actual), IndexedValueType::name($type)));
                }
            }
        }
    }

    /**
     * A call that writes the Durable search attributes, retried while the server refuses it for want
     * of their mapping. On 1.20 a query naming them can pass while the history service still
     * checks writes against a cached namespace without the mapping (#650): a start, and later the
     * continue-as-new of a workflow task, which the history service fails and schedules again
     * (#718). Refused, nothing written, so the retry cannot collide; any other error goes through
     * at once. A mapping that never comes fails with the server's message, which names the
     * attribute.
     *
     * @template T
     *
     * @param string        $what "A start", "A continue-as-new": the subject of the failure message
     * @param callable(): T $call
     *
     * @return T
     */
    private static function onceMapped(string $what, callable $call, float $timeoutSeconds = self::NAMESPACE_READY_TIMEOUT_SECONDS): mixed
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            try {
                return $call();
            } catch (\RuntimeException $unmapped) {
                if (3 !== $unmapped->getCode() || !str_contains($unmapped->getMessage(), 'no mapping defined for search attribute')) {
                    throw $unmapped;
                }
                if (microtime(true) > $deadline) {
                    self::fail(\sprintf('%s that sets %s and %s is still refused %.1f s after their registration: %s', $what, DurableSearchAttributes::WORKFLOW_NAME, DurableSearchAttributes::EXECUTION_ID, $timeoutSeconds, $unmapped->getMessage()));
                }
                // One line per retry, so a green 1.20 run shows whether it met the lag (#718).
                fwrite(\STDERR, \sprintf("%s refused, retrying: %s\n", $what, $unmapped->getMessage()));
                usleep(200_000);
            }
        }
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
