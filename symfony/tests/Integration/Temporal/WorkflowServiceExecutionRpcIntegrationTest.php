<?php

declare(strict_types=1);

namespace App\Tests\Integration\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Query\V1\WorkflowQuery;
use Temporal\Api\Workflowservice\V1\QueryWorkflowRequest;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Smoke tests for {@see WorkflowServiceExecutionRpc} against a real Temporal frontend (gRPC).
 *
 * @see DURABLE_DSN
 */
#[Group('temporal-integration')]
final class WorkflowServiceExecutionRpcIntegrationTest extends TestCase
{
    private static TemporalConnection $connection;

    private static WorkflowServiceClient $client;

    public static function setUpBeforeClass(): void
    {
        $dsn = (string) (getenv('DURABLE_DSN') ?: '');
        if ('' === trim($dsn)) {
            self::markTestSkipped(
                'Set DURABLE_DSN (e.g. temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&tls=0).',
            );
        }
        if (!extension_loaded('grpc')) {
            self::markTestSkipped('PHP grpc extension is required.');
        }

        self::$connection = TemporalConnection::fromDsn($dsn);
        if (!self::temporalTcpReachable(self::$connection)) {
            self::markTestSkipped('Temporal is not reachable at '.self::$connection->target.'.');
        }

        self::$client = WorkflowServiceClientFactory::create(self::$connection);
    }

    private static function temporalTcpReachable(TemporalConnection $connection): bool
    {
        $parts = explode(':', $connection->target, 2);
        $host = $parts[0] ?? '127.0.0.1';
        $port = isset($parts[1]) ? (int) $parts[1] : 7233;
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            \sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            1.0,
            \STREAM_CLIENT_CONNECT,
        );

        if (\is_resource($fp)) {
            fclose($fp);

            return true;
        }

        return false;
    }

    public function testQueryWorkflowFailsForUnknownWorkflowExecution(): void
    {
        $rpc = new WorkflowServiceExecutionRpc(self::$client);

        $req = new QueryWorkflowRequest();
        $req->setNamespace(self::$connection->namespace);
        $req->setExecution(new WorkflowExecution([
            'workflow_id' => 'durable-rpc-missing-'.uniqid('', true),
            'run_id' => '',
        ]));
        $q = new WorkflowQuery();
        $q->setQueryType('__integration_smoke__');
        $req->setQuery($q);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Temporal gRPC error');

        $rpc->queryWorkflow($req);
    }
}
