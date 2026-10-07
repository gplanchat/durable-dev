<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Codec\PayloadCodecInterface;
use Gplanchat\Bridge\Temporal\Codec\PayloadCodecWorkflowServiceClient;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Journal\JournalExecutionIdResolver;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Temporal\Api\Common\V1\Memo;
use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionSignaledEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionStartedEventAttributes;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryResponse;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskFailedResponse;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskFailedResponse;

/**
 * #936: before the worker answers a task as failed for a payload it cannot read, it logs the
 * original exception and the id of the unreadable event. The server only ever gets the message.
 */
final class TheWorkerLogsAnUnreadablePayloadTest extends TestCase
{
    private const WORKFLOW_TASK = ['workflow_id' => 'wf-1', 'run_id' => 'run-1'];

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $records = [];

    public function testAnUndecodablePayloadOnThePolledPageIsLoggedWithItsEvent(): void
    {
        $inner = $this->workflowPolls(self::poll([self::signaled(5, 'go'), self::signaled(7, self::undecodable())]));

        $this->processWorkflowTasks(new PayloadCodecWorkflowServiceClient($inner, self::failingCodec(), $this->logger()));

        $this->assertOneRecord(\RuntimeException::class, 'unknown key k2', 7, self::WORKFLOW_TASK);
    }

    public function testAnUndecodablePayloadOnALaterPageIsLoggedWithItsEvent(): void
    {
        $inner = $this->workflowPolls(self::poll([self::started(1)], 'page-2'));
        $inner->method('GetWorkflowExecutionHistory')->willReturn(new GetWorkflowExecutionHistoryResponse([
            'history' => new History(['events' => [self::signaled(5, 'go'), self::signaled(7, self::undecodable())]]),
        ]));

        $this->processWorkflowTasks(new PayloadCodecWorkflowServiceClient($inner, self::failingCodec(), $this->logger()));

        $this->assertOneRecord(\RuntimeException::class, 'unknown key k2', 7, self::WORKFLOW_TASK);
    }

    public function testAPayloadThatIsNotJsonOnALaterPageIsLoggedWithItsEvent(): void
    {
        $inner = $this->workflowPolls(self::poll([self::started(1)], 'page-2'));
        $inner->method('GetWorkflowExecutionHistory')->willReturn(new GetWorkflowExecutionHistoryResponse([
            'history' => new History(['events' => [self::signaled(5, 'go'), self::signaled(7, new Payload(['metadata' => ['encoding' => 'json/plain'], 'data' => '{not json']))]]),
        ]));

        $this->processWorkflowTasks($inner);

        $this->assertOneRecord(\JsonException::class, 'Syntax error', 7, self::WORKFLOW_TASK);
    }

    public function testAMalformedStartedMemoIsLoggedWithItsEvent(): void
    {
        $started = self::started(1);
        $started->getWorkflowExecutionStartedEventAttributes()?->setMemo(new Memo(['fields' => [
            JournalExecutionIdResolver::MEMO_KEY_DURABLE_EXECUTION_ID => JsonPlainPayload::encode(42),
        ]]));

        $this->processWorkflowTasks($this->workflowPolls(self::poll([$started])));

        $this->assertOneRecord(\JsonException::class, 'holds int', 1, self::WORKFLOW_TASK);
    }

    /**
     * An activity task names no event: its poll response carries no scheduled event id.
     */
    public function testAnUndecodableActivityTaskIsLogged(): void
    {
        $inner = $this->createMock(WorkflowServiceClientInterface::class);
        $inner->method('PollActivityTaskQueue')->willReturn(new PollActivityTaskQueueResponse([
            'task_token' => 'act-token',
            'activity_id' => 'act-1',
            'input' => new Payloads(['payloads' => [self::undecodable()]]),
        ]));
        $inner->expects(self::once())->method('RespondActivityTaskFailed')->willReturn(new RespondActivityTaskFailedResponse());

        (new PayloadCodecWorkflowServiceClient($inner, self::failingCodec(), $this->logger()))->PollActivityTaskQueue(new PollActivityTaskQueueRequest());

        $this->assertOneRecord(\RuntimeException::class, 'unknown key k2', null, ['activity_id' => 'act-1']);
    }

    /**
     * @param array<string, string> $task the keys that identify the task (#939)
     */
    private function assertOneRecord(string $class, string $message, ?int $eventId, array $task): void
    {
        self::assertCount(1, $this->records);
        [$level, , $context] = $this->records[0];
        self::assertSame(LogLevel::ERROR, $level);
        self::assertInstanceOf($class, $context['exception'] ?? null, 'the original exception, which carries the stack trace');
        self::assertStringContainsString($message, $context['exception']->getMessage());
        self::assertArrayHasKey('event_id', $context);
        self::assertSame($eventId, $context['event_id']);
        foreach ($task as $key => $value) {
            self::assertSame($value, $context[$key] ?? null, $key);
        }
    }

    private function processWorkflowTasks(WorkflowServiceClientInterface $client): void
    {
        $connection = new TemporalConnection('localhost:7233', 'test-namespace');
        $runner = new WorkflowTaskRunner(new TemporalHistoryCursor($client, 'test-namespace'), new WorkflowRegistry(), $connection);

        $polls = 0;
        (new WorkflowTaskProcessor($client, $connection, $runner, $this->logger()))->run(static function () use (&$polls): bool {
            return ++$polls < 2;
        });
    }

    private function workflowPolls(PollWorkflowTaskQueueResponse $task): WorkflowServiceClientInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $inner = $this->createMock(WorkflowServiceClientInterface::class);
        $inner->method('PollWorkflowTaskQueue')->willReturnOnConsecutiveCalls($task, new PollWorkflowTaskQueueResponse());
        $inner->expects(self::once())->method('RespondWorkflowTaskFailed')->willReturn(new RespondWorkflowTaskFailedResponse());

        return $inner;
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

    /**
     * @param list<HistoryEvent> $events
     */
    private static function poll(array $events, string $nextPageToken = ''): PollWorkflowTaskQueueResponse
    {
        return new PollWorkflowTaskQueueResponse([
            'task_token' => 'wf-token',
            'workflow_execution' => new WorkflowExecution(['workflow_id' => 'wf-1', 'run_id' => 'run-1']),
            'history' => new History(['events' => $events]),
            'next_page_token' => $nextPageToken,
        ]);
    }

    private static function started(int $eventId): HistoryEvent
    {
        return new HistoryEvent([
            'event_id' => $eventId,
            'event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED,
            'workflow_execution_started_event_attributes' => new WorkflowExecutionStartedEventAttributes(),
        ]);
    }

    private static function signaled(int $eventId, string|Payload $input): HistoryEvent
    {
        $payload = $input instanceof Payload ? $input : new Payload(['metadata' => ['encoding' => 'json/plain'], 'data' => json_encode($input)]);

        return new HistoryEvent([
            'event_id' => $eventId,
            'event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED,
            'workflow_execution_signaled_event_attributes' => new WorkflowExecutionSignaledEventAttributes([
                'signal_name' => 'go',
                'input' => new Payloads(['payloads' => [$payload]]),
            ]),
        ]);
    }

    private static function undecodable(): Payload
    {
        return new Payload(['metadata' => ['encoding' => 'binary/encrypted'], 'data' => 'ciphertext']);
    }

    private static function failingCodec(): PayloadCodecInterface
    {
        return new class implements PayloadCodecInterface {
            public function encode(Payload $payload): Payload
            {
                return $payload;
            }

            public function decode(Payload $payload): Payload
            {
                if ('ciphertext' === $payload->getData()) {
                    throw new \RuntimeException('unknown key k2');
                }

                return $payload;
            }
        };
    }
}
