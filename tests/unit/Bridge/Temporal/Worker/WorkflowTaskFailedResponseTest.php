<?php

declare(strict_types=1);

namespace unit\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionStartedEventAttributes;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskFailedRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskFailedResponse;

/**
 * Failing the workflow **task**, and not the execution.
 *
 * Measured against a real server (probe 1.2 of `workflow-replay-divergence-guard`): raising from
 * workflow code produces `WORKFLOW_TASK_COMPLETED` then `WORKFLOW_EXECUTION_FAILED`, and putting
 * the old code back resurrects nothing. `RespondWorkflowTaskFailed` existed only as a generated
 * stub.
 *
 * Without that path, a replay guard can only trade a silent corruption for a dead execution. With
 * it, the history stays intact and the task is replayed.
 */
final class WorkflowTaskFailedResponseTest extends TestCase
{
    private WorkflowServiceClientInterface $grpcClient;
    private TemporalConnection $connection;

    protected function setUp(): void
    {
        $this->grpcClient = $this->createMock(WorkflowServiceClientInterface::class);
        $this->connection = new TemporalConnection('localhost:7233', 'test-namespace');
    }

    public function testWorkflowTaskFailureRespondsTaskFailedAndNeverCompletes(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory(
            'DivergentWorkflow',
            static fn(array $payload) => static function (WorkflowEnvironment $env): never {
                throw new WorkflowTaskFailure('replay divergence at activity slot 2');
            },
        );

        $poll = $this->buildPoll('token-div', 'wf-div', 'DivergentWorkflow');

        $this->grpcClient
            ->expects($this->once())
            ->method('PollWorkflowTaskQueue')
            ->willReturn($poll);

        // The point of the whole exercise: no command is sent, therefore no workflow failure
        // command. The execution learns nothing from that attempt.
        $this->grpcClient
            ->expects($this->never())
            ->method('RespondWorkflowTaskCompleted');

        $captured = null;
        $this->grpcClient
            ->expects($this->once())
            ->method('RespondWorkflowTaskFailed')
            ->willReturnCallback(function (RespondWorkflowTaskFailedRequest $req) use (&$captured) {
                $captured = $req;

                return new RespondWorkflowTaskFailedResponse();
            });

        $processor = new WorkflowTaskProcessor(
            $this->grpcClient,
            $this->connection,
            new WorkflowTaskRunner(new TemporalHistoryCursor($this->grpcClient, 'test-namespace'), $registry, $this->connection),
        );

        self::assertTrue($processor->processOne());

        self::assertNotNull($captured);
        self::assertSame('token-div', $captured->getTaskToken());
        self::assertSame('test-namespace', $captured->getNamespace());
        self::assertStringContainsString(
            'replay divergence at activity slot 2',
            $captured->getFailure()?->getMessage() ?? '',
            'The guard message must travel: without it, the task fails without saying why.',
        );
    }

    public function testAnOrdinaryThrowStillFailsTheExecution(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory(
            'BoomWorkflow',
            static fn(array $payload) => static function (WorkflowEnvironment $env): never {
                throw new \DomainException('métier cassé');
            },
        );

        $poll = $this->buildPoll('token-boom', 'wf-boom', 'BoomWorkflow');

        $this->grpcClient
            ->expects($this->once())
            ->method('PollWorkflowTaskQueue')
            ->willReturn($poll);

        // The distinction is the subject: only `WorkflowTaskFailure` fails the task.
        $this->grpcClient
            ->expects($this->never())
            ->method('RespondWorkflowTaskFailed');

        $this->grpcClient
            ->expects($this->once())
            ->method('RespondWorkflowTaskCompleted')
            ->willReturnCallback(fn() => new \Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedResponse());

        $processor = new WorkflowTaskProcessor(
            $this->grpcClient,
            $this->connection,
            new WorkflowTaskRunner(new TemporalHistoryCursor($this->grpcClient, 'test-namespace'), $registry, $this->connection),
        );

        self::assertTrue($processor->processOne());
    }

    private function buildPoll(string $token, string $workflowId, string $type): PollWorkflowTaskQueueResponse
    {
        $started = new HistoryEvent();
        $started->setEventId(1);
        $started->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED);
        $started->setWorkflowExecutionStartedEventAttributes(new WorkflowExecutionStartedEventAttributes());

        $history = new History();
        $history->setEvents([$started]);

        $exec = new WorkflowExecution();
        $exec->setWorkflowId($workflowId);

        $wfType = new WorkflowType();
        $wfType->setName($type);

        $poll = new PollWorkflowTaskQueueResponse();
        $poll->setTaskToken($token);
        $poll->setWorkflowExecution($exec);
        $poll->setWorkflowType($wfType);
        $poll->setHistory($history);
        $poll->setNextPageToken('');

        return $poll;
    }
}
