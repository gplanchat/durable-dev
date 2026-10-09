<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceActivityRpc;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceNexusRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\ActivityTaskFailedCause;
use Temporal\Api\Enums\V1\NexusHandlerErrorRetryBehavior;
use Temporal\Api\Nexus\V1\Request as NexusRequest;
use Temporal\Api\Nexus\V1\StartOperationRequest;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\PollNexusTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskFailedRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskFailedResponse;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskFailedRequest;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskFailedResponse;

/**
 * #938: a task whose input is not JSON is answered as failed, the original error is logged, and
 * the worker polls again. Left to throw, it stopped the worker, then the next one (#775).
 */
final class AnUnreadableTaskInputTest extends TestCase
{
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $records = [];

    /**
     * @return iterable<string, array{?Payloads, class-string<\Throwable>, string}>
     */
    public static function unreadableActivityInputs(): iterable
    {
        yield 'not JSON' => [new Payloads(['payloads' => [self::notJson()]]), \JsonException::class, 'Syntax error'];
        yield 'no input' => [null, \InvalidArgumentException::class, 'missing input'];
        yield 'not an object' => [new Payloads(['payloads' => [self::json('"text"')]]), \InvalidArgumentException::class, 'expected JSON object'];
        yield 'no activityId' => [new Payloads(['payloads' => [self::json('{"executionId":"e-1","activityName":"charge"}')]]), \InvalidArgumentException::class, '"activityId"'];
    }

    /**
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('unreadableActivityInputs')]
    public function testAnUnreadableActivityInputFailsTheTaskForGoodAndTheWorkerPollsAgain(?Payloads $input, string $class, string $message): void
    {
        $failed = null;
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->expects(self::exactly(2))->method('PollActivityTaskQueue')->willReturnOnConsecutiveCalls(
            new PollActivityTaskQueueResponse(['task_token' => 'act-token', 'activity_id' => 'act-1', 'workflow_execution' => new WorkflowExecution(['workflow_id' => 'wf-1', 'run_id' => 'run-1']), 'input' => $input]),
            new PollActivityTaskQueueResponse(),
        );
        $client->expects(self::once())->method('RespondActivityTaskFailed')->willReturnCallback(static function (RespondActivityTaskFailedRequest $request) use (&$failed): RespondActivityTaskFailedResponse {
            $failed = $request;

            return new RespondActivityTaskFailedResponse();
        });
        $store = new InMemoryEventStore();
        $sender = $this->createMock(ActivityHeartbeatSenderInterface::class);
        $worker = new TemporalActivityWorker(
            new WorkflowServiceActivityRpc($client),
            new TemporalConnection('localhost:7233', 'test-namespace'),
            new ActivityMessageProcessor($store, new NoopActivityTransport(), new RegistryActivityExecutor(), new NullWorkflowResumeDispatcher(), $sender),
            $store,
            $sender,
            $this->logger(),
        );

        $worker->pollOnce();
        $worker->pollOnce();

        self::assertInstanceOf(RespondActivityTaskFailedRequest::class, $failed);
        self::assertSame('act-token', $failed->getTaskToken());
        self::assertSame($class, $failed->getFailure()?->getApplicationFailureInfo()?->getType());
        self::assertTrue($failed->getFailure()->getApplicationFailureInfo()->getNonRetryable(), 'the same input fails the same way on every attempt');
        self::assertStringContainsString($message, $failed->getFailure()->getMessage());
        self::assertSame('', $failed->getFailure()->getStackTrace(), 'a stack trace may quote the input');
        self::assertSame(ActivityTaskFailedCause::ACTIVITY_TASK_FAILED_CAUSE_ACTIVITY_WORKER_UNHANDLED_FAILURE, $failed->getCause());
        $context = $this->assertOneErrorRecord($class);
        self::assertSame('RespondActivityTaskFailed', $context['rpc'] ?? null);
        self::assertSame('act-1', $context['activity_id'] ?? null);
        self::assertSame('wf-1', $context['workflow_id'] ?? null);
        self::assertSame('run-1', $context['run_id'] ?? null);
    }

    public function testANonJsonNexusInputIsLoggedAndTheWorkerPollsAgain(): void
    {
        $failed = null;
        $start = new StartOperationRequest(['service' => 'billing', 'operation' => 'charge', 'request_id' => 'req-1', 'payload' => self::notJson()]);
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->expects(self::exactly(2))->method('PollNexusTaskQueue')->willReturnOnConsecutiveCalls(
            new PollNexusTaskQueueResponse(['task_token' => 'nexus-token', 'request' => new NexusRequest(['start_operation' => $start])]),
            new PollNexusTaskQueueResponse(),
        );
        $client->expects(self::once())->method('RespondNexusTaskFailed')->willReturnCallback(static function (RespondNexusTaskFailedRequest $request) use (&$failed): RespondNexusTaskFailedResponse {
            $failed = $request;

            return new RespondNexusTaskFailedResponse();
        });
        $worker = new TemporalNexusWorker(
            new WorkflowServiceNexusRpc($client),
            new TemporalConnection('localhost:7233', 'test-namespace'),
            NexusOperationRegistry::routedBy('temporal'),
            $this->logger(),
        );

        $worker->pollOnce();
        $worker->pollOnce();

        self::assertInstanceOf(RespondNexusTaskFailedRequest::class, $failed);
        self::assertSame('nexus-token', $failed->getTaskToken());
        self::assertSame('INTERNAL', $failed->getError()?->getErrorType());
        self::assertSame(NexusHandlerErrorRetryBehavior::NEXUS_HANDLER_ERROR_RETRY_BEHAVIOR_RETRYABLE, $failed->getError()->getRetryBehavior());
        $context = $this->assertOneErrorRecord(\JsonException::class);
        self::assertSame('RespondNexusTaskFailed', $context['rpc'] ?? null);
        self::assertSame('billing', $context['service'] ?? null);
        self::assertSame('charge', $context['operation'] ?? null);
        self::assertSame('req-1', $context['request_id'] ?? null);
    }

    /** @return array<string, mixed> */
    private function assertOneErrorRecord(string $class): array
    {
        self::assertCount(1, $this->records);
        [$level, , $context] = $this->records[0];
        self::assertSame(LogLevel::ERROR, $level);
        self::assertInstanceOf($class, $context['exception'] ?? null, 'the original exception, which carries the stack trace');
        self::assertArrayHasKey('event_id', $context);
        self::assertNull($context['event_id'], 'the poll response of this task names no event');

        return $context;
    }

    private function logger(): AbstractLogger
    {
        return new class (function (string $level, string $message, array $context): void {
            $this->records[] = [$level, $message, $context];
        }) extends AbstractLogger {
            /** @param \Closure(string, string, array<string, mixed>): void $record */
            public function __construct(private readonly \Closure $record) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->record)((string) $level, (string) $message, $context);
            }
        };
    }

    private static function notJson(): Payload
    {
        return self::json('{not json');
    }

    private static function json(string $data): Payload
    {
        return new Payload(['metadata' => ['encoding' => 'json/plain'], 'data' => $data]);
    }
}
