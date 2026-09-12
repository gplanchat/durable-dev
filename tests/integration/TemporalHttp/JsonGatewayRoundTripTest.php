<?php

declare(strict_types=1);

namespace integration\TemporalHttp;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Enums\V1\WorkflowExecutionStatus;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryRequest;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\TerminateWorkflowExecutionRequest;

/**
 * The client side of the bridge through the server's JSON gateway, against a real server:
 *
 *     temporal server start-dev --namespace durable-test --port 7233 --http-port 7243
 *     DURABLE_TEMPORAL_HTTP_ADDRESS=127.0.0.1:7243 vendor/bin/phpunit --testsuite integration
 *
 * No worker is involved: a started execution can be described, read, and terminated without
 * anybody polling its task queue, which is exactly the surface the gateway exposes.
 */
final class JsonGatewayRoundTripTest extends TestCase
{
    private TemporalConnection $connection;
    private WorkflowServiceClientInterface $client;
    private string $workflowId;

    protected function setUp(): void
    {
        $address = getenv('DURABLE_TEMPORAL_HTTP_ADDRESS');
        if (false === $address || '' === $address) {
            self::markTestSkipped('DURABLE_TEMPORAL_HTTP_ADDRESS not set: no Temporal HTTP gateway.');
        }

        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            identity: 'durable-json-gateway-it',
            transport: TemporalConnection::TRANSPORT_HTTP,
        );
        $this->client = WorkflowServiceClientFactory::create($this->connection);
        $this->workflowId = 'json-gateway-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $request = new TerminateWorkflowExecutionRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $this->workflowId]));
        $request->setReason('end of test');

        try {
            $this->client->TerminateWorkflowExecution($request, [], ['timeout' => 5_000_000]);
        } catch (\RuntimeException) {
            // Never started, or already gone: not the subject of the test.
        }
    }

    public function testAnExecutionStartedOverJsonCanBeDescribedAndReadOverJson(): void
    {
        $start = new StartWorkflowExecutionRequest();
        $start->setNamespace($this->connection->namespace->name());
        $start->setWorkflowId($this->workflowId);
        $start->setWorkflowType(new WorkflowType(['name' => 'JsonGatewayProbe']));
        $start->setTaskQueue(new TaskQueue(['name' => 'json-gateway-' . bin2hex(random_bytes(4))]));
        $start->setRequestId(bin2hex(random_bytes(8)));
        $start->setIdentity($this->connection->identity);

        $started = $this->client->StartWorkflowExecution($start, [], ['timeout' => 10_000_000]);
        self::assertNotSame('', $started->getRunId());

        $describe = new DescribeWorkflowExecutionRequest();
        $describe->setNamespace($this->connection->namespace->name());
        $describe->setExecution(new WorkflowExecution(['workflow_id' => $this->workflowId]));
        $described = $this->client->DescribeWorkflowExecution($describe, [], ['timeout' => 10_000_000]);
        self::assertSame(WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, $described->getWorkflowExecutionInfo()?->getStatus());
        self::assertSame($started->getRunId(), $described->getWorkflowExecutionInfo()?->getExecution()?->getRunId());

        // A GET route: the execution goes in the path, the page size and the filter in the query.
        $history = new GetWorkflowExecutionHistoryRequest();
        $history->setNamespace($this->connection->namespace->name());
        $history->setExecution(new WorkflowExecution(['workflow_id' => $this->workflowId, 'run_id' => $started->getRunId()]));
        $history->setMaximumPageSize(10);
        $page = $this->client->GetWorkflowExecutionHistory($history, [], ['timeout' => 10_000_000]);
        $events = iterator_to_array($page->getHistory()?->getEvents() ?? [], false);
        self::assertNotSame([], $events);
        self::assertSame(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED, $events[0]->getEventType());
    }

    public function testAnUnknownExecutionIsNotFoundByGrpcCode(): void
    {
        $describe = new DescribeWorkflowExecutionRequest();
        $describe->setNamespace($this->connection->namespace->name());
        $describe->setExecution(new WorkflowExecution(['workflow_id' => 'never-started-' . bin2hex(random_bytes(4))]));

        try {
            $this->client->DescribeWorkflowExecution($describe, [], ['timeout' => 10_000_000]);
            self::fail('Describing an unknown execution must fail.');
        } catch (\RuntimeException $e) {
            self::assertSame(5, $e->getCode(), $e->getMessage());
        }
    }
}
