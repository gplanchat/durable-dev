<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
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
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedResponse;
use unit\Durable\Fixtures\SuiteActivities;

/**
 * Tests for WorkflowTaskProcessor — the poll → execute → respond loop.
 *
 * Strategy:
 * - Mock WorkflowServiceClientInterface.PollWorkflowTaskQueue to return an inline-history PollResponse.
 * - Mock WorkflowServiceClientInterface.RespondWorkflowTaskCompleted to capture the sent commands.
 * - Use a real WorkflowTaskRunner (final, can't mock) backed by the real TemporalHistoryCursor
 *   reading from the inline history (no gRPC pagination since next_page_token = '').
 */
final class WorkflowTaskProcessorTest extends TestCase
{
    private WorkflowServiceClientInterface $grpcClient;
    private TemporalConnection $connection;

    protected function setUp(): void
    {
        $this->grpcClient = $this->createMock(WorkflowServiceClientInterface::class);
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
            ->willReturn($emptyPoll);

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
        $registry->registerFactory(
            'ImmediateWorkflow',
            static fn(array $payload)
            => static fn(WorkflowEnvironment $env): string => 'done',
        );

        $poll = self::buildPoll('my-token', 'wf-1', 'ImmediateWorkflow', [
            self::makeStarted(1),
        ]);

        $capturedRequest = null;
        $this->grpcClient
            ->expects($this->once())
            ->method('PollWorkflowTaskQueue')
            ->willReturn($poll);

        $this->grpcClient
            ->expects($this->once())
            ->method('RespondWorkflowTaskCompleted')
            ->willReturnCallback(function (RespondWorkflowTaskCompletedRequest $req) use (&$capturedRequest) {
                $capturedRequest = $req;

                return new RespondWorkflowTaskCompletedResponse();
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
        $registry->registerFactory(
            'ActivityWorkflow',
            static fn(array $payload)
            => static function (WorkflowEnvironment $env): string {
                return $env->await($env->activityStub(SuiteActivities::class)->greet('World'));
            },
        );

        $poll = self::buildPoll('token-act', 'wf-2', 'ActivityWorkflow', [
            self::makeStarted(1),
        ]);

        $capturedRequest = null;
        $this->grpcClient->method('PollWorkflowTaskQueue')
            ->willReturn($poll);
        $this->grpcClient->method('RespondWorkflowTaskCompleted')
            ->willReturnCallback(function (RespondWorkflowTaskCompletedRequest $req) use (&$capturedRequest) {
                $capturedRequest = $req;

                return new RespondWorkflowTaskCompletedResponse();
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
        $registry->registerFactory(
            'ActivityWorkflow',
            static fn(array $payload)
            => static function (WorkflowEnvironment $env): string {
                return $env->await($env->activityStub(SuiteActivities::class)->greet('World'));
            },
        );

        $poll = self::buildPoll('token-replay', 'wf-3', 'ActivityWorkflow', [
            self::makeStarted(1),
            self::makeActivityScheduled(2, 'slot-0'),
            self::makeActivityCompleted(3, 2, 'Hello World'),
        ]);

        $capturedRequest = null;
        $this->grpcClient->method('PollWorkflowTaskQueue')
            ->willReturn($poll);
        $this->grpcClient->method('RespondWorkflowTaskCompleted')
            ->willReturnCallback(function (RespondWorkflowTaskCompletedRequest $req) use (&$capturedRequest) {
                $capturedRequest = $req;

                return new RespondWorkflowTaskCompletedResponse();
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
        // A class, and a query declared by attribute: that is the only form from now on. The
        // imperative registration from a closure short-circuited the declaration, and the engine
        // no longer has a verb to lend it for that.
        $registry = new WorkflowRegistry();
        $registry->registerClass(QueryableWorkflow::class);

        $poll = self::buildPoll('token-query', 'wf-4', 'queryable', [
            self::makeStarted(1),
        ], ['q1' => 'getStatus']);

        $capturedRequest = null;
        $this->grpcClient->method('PollWorkflowTaskQueue')
            ->willReturn($poll);
        $this->grpcClient->method('RespondWorkflowTaskCompleted')
            ->willReturnCallback(function (RespondWorkflowTaskCompletedRequest $req) use (&$capturedRequest) {
                $capturedRequest = $req;

                return new RespondWorkflowTaskCompletedResponse();
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
        $registry->registerFactory(
            'SimpleWorkflow',
            static fn(array $payload)
            => static function (WorkflowEnvironment $env): string {
                $env->await(static fn(): bool => false);

                return 'completed';
            },
        );

        $poll = self::buildPoll('token-unknown-query', 'wf-5', 'SimpleWorkflow', [
            self::makeStarted(1),
        ], ['q2' => 'nonExistentQuery']);

        $capturedRequest = null;
        $this->grpcClient->method('PollWorkflowTaskQueue')
            ->willReturn($poll);
        $this->grpcClient->method('RespondWorkflowTaskCompleted')
            ->willReturnCallback(function (RespondWorkflowTaskCompletedRequest $req) use (&$capturedRequest) {
                $capturedRequest = $req;

                return new RespondWorkflowTaskCompletedResponse();
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
            ->willReturn($emptyPoll);

        $processor = $this->makeProcessor(new WorkflowRegistry());

        $callCount = 0;
        $processor->run(function (bool $processed) use (&$callCount): bool {
            ++$callCount;

            return $callCount < 3;
        });

        self::assertSame(3, $callCount);
    }

    public function testAnsweringAQueryLeavesTheCommandsUntouched(): void
    {
        // Answering a query must change nothing in the execution: the same poll, with and
        // without a query, produces the same commands. That is what makes the query replayable —
        // it records no fact a replay would have to take into account.
        //
        // Read in the code, that would be an argument. Here it is an assertion, and that is the
        // difference.
        $registry = new WorkflowRegistry();
        $registry->registerClass(QueryableSchedulingWorkflow::class);

        $withQuery = self::buildPoll('token-q-yes', 'wf-6', 'queryable-scheduling', [
            self::makeStarted(1),
        ], ['q3' => 'getStatus']);

        $withoutQuery = self::buildPoll('token-q-no', 'wf-6', 'queryable-scheduling', [
            self::makeStarted(1),
        ]);

        $this->grpcClient->method('PollWorkflowTaskQueue')
            ->willReturnOnConsecutiveCalls(
                $withQuery,
                $withoutQuery,
            );

        $captured = [];
        $this->grpcClient->method('RespondWorkflowTaskCompleted')
            ->willReturnCallback(function (RespondWorkflowTaskCompletedRequest $req) use (&$captured) {
                $captured[] = $req;

                return new RespondWorkflowTaskCompletedResponse();
            });

        $processor = $this->makeProcessor($registry);
        $processor->processOne();
        $processor->processOne();

        self::assertCount(2, $captured);
        self::assertSame(
            self::serializedCommands($captured[1]),
            self::serializedCommands($captured[0]),
        );

        self::assertSame(
            QueryResultType::QUERY_RESULT_TYPE_ANSWERED,
            $captured[0]->getQueryResults()['q3']->getResultType(),
        );
        self::assertCount(0, $captured[1]->getQueryResults());
    }

    public function testAQueryHandlerThatRaisesFailsTheQueryAndNotTheExecution(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerClass(RaisingQueryWorkflow::class);

        $poll = self::buildPoll('token-q-raise', 'wf-8', 'queryable-raising', [
            self::makeStarted(1),
        ], ['q4' => 'getStatus']);

        $capturedRequest = null;
        $this->grpcClient->method('PollWorkflowTaskQueue')
            ->willReturn($poll);
        $this->grpcClient->method('RespondWorkflowTaskCompleted')
            ->willReturnCallback(function (RespondWorkflowTaskCompletedRequest $req) use (&$capturedRequest) {
                $capturedRequest = $req;

                return new RespondWorkflowTaskCompletedResponse();
            });

        $processor = $this->makeProcessor($registry);
        $processor->processOne();

        self::assertNotNull($capturedRequest);
        self::assertSame(
            QueryResultType::QUERY_RESULT_TYPE_FAILED,
            $capturedRequest->getQueryResults()['q4']->getResultType(),
        );

        // The execution carries on its way: the command it had to emit leaves all the same.
        self::assertCount(1, $capturedRequest->getCommands());
        self::assertSame(
            CommandType::COMMAND_TYPE_SCHEDULE_ACTIVITY_TASK,
            $capturedRequest->getCommands()[0]->getCommandType(),
        );
    }

    /**
     * The commands of a response, serialized, with activity identifiers neutralized.
     *
     * The identifier is a UUIDv7 drawn on every execution: two executions of the same task cannot
     * be identical to the bit, and that is not what is being established. Everything else —
     * command type, activity name, queue, payload, delays — must be.
     *
     * @return list<string>
     */
    private static function serializedCommands(RespondWorkflowTaskCompletedRequest $request): array
    {
        $serialized = [];
        foreach ($request->getCommands() as $command) {
            // The payloads travel in base64: without decoding them, the identifier they repeat
            // would escape the neutralization and the comparison would no longer say anything.
            $readable = (string) preg_replace_callback(
                '/"data":"([A-Za-z0-9+\/=]+)"/',
                static fn(array $m): string => '"data":"' . base64_decode($m[1], true) . '"',
                $command->serializeToJsonString(),
            );

            $serialized[] = (string) preg_replace(
                '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
                '<uuid>',
                $readable,
            );
        }

        return $serialized;
    }
}

#[\Gplanchat\Durable\Attribute\AsWorkflow(name: 'queryable')]
final class QueryableWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[\Gplanchat\Durable\Attribute\AsQueryMethod('getStatus')]
    public function status(): string
    {
        return 'running';
    }

    #[\Gplanchat\Durable\Attribute\AsWorkflowMethod]
    public function run(): string
    {
        // Suspends on a condition nothing satisfies in this task, which lets the query be asked
        // of an execution still running.
        $this->environment->await(static fn(): bool => false);

        return 'completed';
    }
}

/**
 * An execution that emits a command *and* answers a query: without the command, comparing the
 * responses with and without a query would amount to comparing two empty lists.
 */
#[\Gplanchat\Durable\Attribute\AsWorkflow(name: 'queryable-scheduling')]
final class QueryableSchedulingWorkflow
{
    private readonly ActivityStub $greetings;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->greetings = $environment->activityStub(SuiteActivities::class);
    }

    #[\Gplanchat\Durable\Attribute\AsQueryMethod('getStatus')]
    public function status(): string
    {
        return 'running';
    }

    #[\Gplanchat\Durable\Attribute\AsWorkflowMethod]
    public function run(): string
    {
        return $this->environment->await($this->greetings->greet('World'));
    }
}

#[\Gplanchat\Durable\Attribute\AsWorkflow(name: 'queryable-raising')]
final class RaisingQueryWorkflow
{
    private readonly ActivityStub $greetings;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->greetings = $environment->activityStub(SuiteActivities::class);
    }

    #[\Gplanchat\Durable\Attribute\AsQueryMethod('getStatus')]
    public function status(): string
    {
        throw new \RuntimeException('cette query ne sait pas répondre');
    }

    #[\Gplanchat\Durable\Attribute\AsWorkflowMethod]
    public function run(): string
    {
        return $this->environment->await($this->greetings->greet('World'));
    }
}
