<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Durable\Attribute\QueryMethod;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use Grpc\UnaryCall;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Enums\V1\QueryResultType;
use Temporal\Api\History\V1\ActivityTaskCompletedEventAttributes;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionStartedEventAttributes;
use Temporal\Api\Query\V1\WorkflowQuery;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Tests for WorkflowTaskProcessor — the poll → execute → respond loop.
 *
 * Strategy:
 * - Mock WorkflowServiceClient.PollWorkflowTaskQueue to return an inline-history PollResponse.
 * - Mock WorkflowServiceClient.RespondWorkflowTaskCompleted to capture the sent commands.
 * - Use a real WorkflowTaskRunner (final, can't mock) backed by the real TemporalHistoryCursor
 *   reading from the inline history (no gRPC pagination since next_page_token = '').
 */
final class WorkflowTaskProcessorTest extends TestCase
{
    private WorkflowServiceClient $grpcClient;
    private TemporalConnection $connection;

    protected function setUp(): void
    {
        $this->grpcClient = $this->createMock(WorkflowServiceClient::class);
        $this->connection = new TemporalConnection('localhost:7233', 'test-namespace');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeProcessor(WorkflowRegistry $registry): WorkflowTaskProcessor
    {
        $cursor = new TemporalHistoryCursor($this->grpcClient, 'test-namespace');
        $runner = new WorkflowTaskRunner($cursor, $registry, $this->connection);

        return new WorkflowTaskProcessor($this->grpcClient, $this->connection, $runner);
    }

    private function makeUnaryCallReturning(object $response): UnaryCall
    {
        $status = new \stdClass();
        $status->code = \Grpc\STATUS_OK;
        $status->details = '';

        $call = $this->createMock(UnaryCall::class);
        $call->method('wait')->willReturn([$response, $status]);

        return $call;
    }

    private static function buildPoll(
        string $token,
        string $workflowId,
        string $workflowTypeName,
        array $events,
        array $queries = [],
    ): PollWorkflowTaskQueueResponse {
        $history = new History();
        $history->setEvents($events);

        $exec = new WorkflowExecution();
        $exec->setWorkflowId($workflowId);

        $wfType = new WorkflowType();
        $wfType->setName($workflowTypeName);

        $poll = new PollWorkflowTaskQueueResponse();
        $poll->setTaskToken($token);
        $poll->setWorkflowExecution($exec);
        $poll->setWorkflowType($wfType);
        $poll->setHistory($history);
        $poll->setNextPageToken('');

        foreach ($queries as $queryId => $queryType) {
            $query = new WorkflowQuery();
            $query->setQueryType($queryType);
            $poll->getQueries()[$queryId] = $query;
        }

        return $poll;
    }

    private static function makeEvent(int $id, int $type): HistoryEvent
    {
        $e = new HistoryEvent();
        $e->setEventId($id);
        $e->setEventType($type);

        return $e;
    }

    private static function makeStarted(int $id, array $input = []): HistoryEvent
    {
        $e = self::makeEvent($id, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED);
        $attr = new WorkflowExecutionStartedEventAttributes();
        $ps = new Payloads();
        $ps->setPayloads([JsonPlainPayload::encode($input)]);
        $attr->setInput($ps);
        $e->setWorkflowExecutionStartedEventAttributes($attr);

        return $e;
    }

    private static function makeActivityScheduled(int $id, string $activityId): HistoryEvent
    {
        $e = self::makeEvent($id, EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED);
        $attr = new ActivityTaskScheduledEventAttributes();
        $attr->setActivityId($activityId);
        $e->setActivityTaskScheduledEventAttributes($attr);

        return $e;
    }

    private static function makeActivityCompleted(int $id, int $scheduledEventId, mixed $result): HistoryEvent
    {
        $e = self::makeEvent($id, EventType::EVENT_TYPE_ACTIVITY_TASK_COMPLETED);
        $attr = new ActivityTaskCompletedEventAttributes();
        $attr->setScheduledEventId($scheduledEventId);
        $ps = new Payloads();
        $ps->setPayloads([JsonPlainPayload::encode($result)]);
        $attr->setResult($ps);
        $e->setActivityTaskCompletedEventAttributes($attr);

        return $e;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testEmptyPollReturnsFalseWithoutResponding(): void
    {
        $emptyPoll = new PollWorkflowTaskQueueResponse();
        $emptyPoll->setTaskToken('');

        $this->grpcClient
            ->expects($this->once())
            ->method('PollWorkflowTaskQueue')
            ->willReturn($this->makeUnaryCallReturning($emptyPoll));

        $this->grpcClient
            ->expects($this->never())
            ->method('RespondWorkflowTaskCompleted');

        $processor = $this->makeProcessor(new WorkflowRegistry());
        $processed = $processor->processOne();

        self::assertFalse($processed);
    }

    public function testWorkflowCompletesAndRespondsWithCompleteCommand(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory('ImmediateWorkflow', static fn (array $payload) =>
            static fn (WorkflowEnvironment $env): string => 'done'
        );

        $poll = self::buildPoll('my-token', 'wf-1', 'ImmediateWorkflow', [
            self::makeStarted(1),
        ]);

        $capturedRequest = null;
        $this->grpcClient
            ->expects($this->once())
            ->method('PollWorkflowTaskQueue')
            ->willReturn($this->makeUnaryCallReturning($poll));

        $this->grpcClient
            ->expects($this->once())
            ->method('RespondWorkflowTaskCompleted')
            ->willReturnCallback(function (RespondWorkflowTaskCompletedRequest $req) use (&$capturedRequest) {
                $capturedRequest = $req;

                return $this->makeUnaryCallReturning(new RespondWorkflowTaskCompletedResponse());
            });

        $processor = $this->makeProcessor($registry);
        $processed = $processor->processOne();

        self::assertTrue($processed);
        self::assertNotNull($capturedRequest);
        self::assertSame('my-token', $capturedRequest->getTaskToken());
        self::assertCount(1, $capturedRequest->getCommands());
        self::assertSame(
            CommandType::COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION,
            $capturedRequest->getCommands()[0]->getCommandType(),
        );
    }

    public function testNewActivityEmitsScheduleCommandInResponse(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory('ActivityWorkflow', static fn (array $payload) =>
            static function (WorkflowEnvironment $env): string {
                return $env->await($env->activity('greet', ['name' => 'World']));
            }
        );

        $poll = self::buildPoll('token-act', 'wf-2', 'ActivityWorkflow', [
            self::makeStarted(1),
        ]);

        $capturedRequest = null;
        $this->grpcClient->method('PollWorkflowTaskQueue')
            ->willReturn($this->makeUnaryCallReturning($poll));
        $this->grpcClient->method('RespondWorkflowTaskCompleted')
            ->willReturnCallback(function (RespondWorkflowTaskCompletedRequest $req) use (&$capturedRequest) {
                $capturedRequest = $req;

                return $this->makeUnaryCallReturning(new RespondWorkflowTaskCompletedResponse());
            });

        $processor = $this->makeProcessor($registry);
        $processor->processOne();

        self::assertNotNull($capturedRequest);
        self::assertCount(1, $capturedRequest->getCommands());
        self::assertSame(
            CommandType::COMMAND_TYPE_SCHEDULE_ACTIVITY_TASK,
            $capturedRequest->getCommands()[0]->getCommandType(),
        );
    }

    public function testReplayedActivityCompletesWorkflow(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory('ActivityWorkflow', static fn (array $payload) =>
            static function (WorkflowEnvironment $env): string {
                return $env->await($env->activity('greet', ['name' => 'World']));
            }
        );

        $poll = self::buildPoll('token-replay', 'wf-3', 'ActivityWorkflow', [
            self::makeStarted(1),
            self::makeActivityScheduled(2, 'slot-0'),
            self::makeActivityCompleted(3, 2, 'Hello World'),
        ]);

        $capturedRequest = null;
        $this->grpcClient->method('PollWorkflowTaskQueue')
            ->willReturn($this->makeUnaryCallReturning($poll));
        $this->grpcClient->method('RespondWorkflowTaskCompleted')
            ->willReturnCallback(function (RespondWorkflowTaskCompletedRequest $req) use (&$capturedRequest) {
                $capturedRequest = $req;

                return $this->makeUnaryCallReturning(new RespondWorkflowTaskCompletedResponse());
            });

        $processor = $this->makeProcessor($registry);
        $processor->processOne();

        self::assertNotNull($capturedRequest);
        self::assertCount(1, $capturedRequest->getCommands());
        self::assertSame(
            CommandType::COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION,
            $capturedRequest->getCommands()[0]->getCommandType(),
        );
    }

    public function testQueryIsAnsweredInResponse(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory('QueryableWorkflow', static fn (array $payload) =>
            static function (WorkflowEnvironment $env): string {
                $env->registerQueryHandler('getStatus', static fn () => 'running');

                // Suspend workflow (wait for signal that never comes in this task)
                $env->waitSignal('done');

                return 'completed';
            }
        );

        $poll = self::buildPoll('token-query', 'wf-4', 'QueryableWorkflow', [
            self::makeStarted(1),
        ], ['q1' => 'getStatus']);

        $capturedRequest = null;
        $this->grpcClient->method('PollWorkflowTaskQueue')
            ->willReturn($this->makeUnaryCallReturning($poll));
        $this->grpcClient->method('RespondWorkflowTaskCompleted')
            ->willReturnCallback(function (RespondWorkflowTaskCompletedRequest $req) use (&$capturedRequest) {
                $capturedRequest = $req;

                return $this->makeUnaryCallReturning(new RespondWorkflowTaskCompletedResponse());
            });

        $processor = $this->makeProcessor($registry);
        $processor->processOne();

        self::assertNotNull($capturedRequest);

        $queryResults = $capturedRequest->getQueryResults();
        self::assertArrayHasKey('q1', $queryResults);
        self::assertSame(
            QueryResultType::QUERY_RESULT_TYPE_ANSWERED,
            $queryResults['q1']->getResultType(),
        );
    }

    public function testUnknownQueryResultsInFailedQueryResult(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory('SimpleWorkflow', static fn (array $payload) =>
            static function (WorkflowEnvironment $env): string {
                $env->waitSignal('done');

                return 'completed';
            }
        );

        $poll = self::buildPoll('token-unknown-query', 'wf-5', 'SimpleWorkflow', [
            self::makeStarted(1),
        ], ['q2' => 'nonExistentQuery']);

        $capturedRequest = null;
        $this->grpcClient->method('PollWorkflowTaskQueue')
            ->willReturn($this->makeUnaryCallReturning($poll));
        $this->grpcClient->method('RespondWorkflowTaskCompleted')
            ->willReturnCallback(function (RespondWorkflowTaskCompletedRequest $req) use (&$capturedRequest) {
                $capturedRequest = $req;

                return $this->makeUnaryCallReturning(new RespondWorkflowTaskCompletedResponse());
            });

        $processor = $this->makeProcessor($registry);
        $processor->processOne();

        self::assertNotNull($capturedRequest);
        $queryResults = $capturedRequest->getQueryResults();
        self::assertArrayHasKey('q2', $queryResults);
        self::assertSame(
            QueryResultType::QUERY_RESULT_TYPE_FAILED,
            $queryResults['q2']->getResultType(),
        );
    }

    public function testRunLoopStopsWhenShouldContinueReturnsFalse(): void
    {
        $emptyPoll = new PollWorkflowTaskQueueResponse();
        $emptyPoll->setTaskToken('');

        $this->grpcClient
            ->expects($this->exactly(3))
            ->method('PollWorkflowTaskQueue')
            ->willReturn($this->makeUnaryCallReturning($emptyPoll));

        $processor = $this->makeProcessor(new WorkflowRegistry());

        $callCount = 0;
        $processor->run(function (bool $processed) use (&$callCount): bool {
            ++$callCount;

            return $callCount < 3;
        });

        self::assertSame(3, $callCount);
    }
}
