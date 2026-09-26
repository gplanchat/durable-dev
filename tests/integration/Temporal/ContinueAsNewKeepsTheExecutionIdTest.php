<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Journal\JournalExecutionIdResolver;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Memo;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\WorkflowExecutionStatus;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;

/**
 * The server does not carry a memo over a continue-as-new: the successor has only what the command
 * sets. Without `durableExecutionId` the worker runs it under the workflow id, and the run loses
 * the id the application started it with (#560, measured on 1.25.2).
 */
final class ContinueAsNewKeepsTheExecutionIdTest extends TestCase
{
    use FreshNamespace;

    private TemporalConnection $connection;
    private WorkflowServiceClientInterface $client;

    protected function setUp(): void
    {
        $this->connection = self::freshNamespaceConnection();
        $this->client = WorkflowServiceClientFactory::create($this->connection);
    }

    public function testTheSuccessorKeepsTheExecutionIdTheApplicationGaveIt(): void
    {
        $namespace = $this->connection->namespace->name();
        $queue = new TaskQueue(['name' => 'continue-as-new-memo']);
        $memo = new Memo();
        $memo->getFields()[JournalExecutionIdResolver::MEMO_KEY_DURABLE_EXECUTION_ID] = JsonPlainPayload::encode('order/42');
        $this->client->StartWorkflowExecution(new StartWorkflowExecutionRequest([
            'namespace' => $namespace,
            'workflow_id' => 'durable-order-42',
            'workflow_type' => new WorkflowType(['name' => 'App\\OrderWorkflow']),
            'task_queue' => $queue,
            'request_id' => bin2hex(random_bytes(16)),
            'memo' => $memo,
        ]));

        $task = $this->client->PollWorkflowTaskQueue(new PollWorkflowTaskQueueRequest([
            'namespace' => $namespace,
            'task_queue' => $queue,
            'identity' => $this->connection->identity,
        ]), [], ['timeout' => 10_000_000]);
        $buffer = new TemporalWorkflowCommandBuffer($this->connection, 'order/42');
        $buffer->continueAsNew('App\\OrderWorkflow', []);
        $this->client->RespondWorkflowTaskCompleted(new RespondWorkflowTaskCompletedRequest([
            'namespace' => $namespace,
            'task_token' => $task->getTaskToken(),
            'identity' => $this->connection->identity,
            'commands' => $buffer->flush(),
        ]));

        $successor = $this->client->DescribeWorkflowExecution(new DescribeWorkflowExecutionRequest([
            'namespace' => $namespace,
            'execution' => new WorkflowExecution(['workflow_id' => 'durable-order-42']),
        ]))->getWorkflowExecutionInfo();

        self::assertNotSame($task->getWorkflowExecution()?->getRunId(), $successor?->getExecution()?->getRunId(), 'a successor, not the first run');
        self::assertSame(WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, $successor?->getStatus());
        $field = $successor->getMemo()?->getFields()[JournalExecutionIdResolver::MEMO_KEY_DURABLE_EXECUTION_ID] ?? null;
        self::assertNotNull($field, 'the successor carries the durableExecutionId memo');
        self::assertSame('order/42', JsonPlainPayload::decode($field));
    }
}
