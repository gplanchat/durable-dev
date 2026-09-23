<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Gplanchat\Bridge\Temporal\AbstractWorkflowServiceClient;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Google\Protobuf\Internal\Message;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryResponse;

/**
 * Unit tests for {@see TemporalHistoryCursor::closeEvent()}.
 *
 * Uses a test double of the bridge's WorkflowServiceClientInterface, without any real gRPC,
 * in order to control the responses of GetWorkflowExecutionHistory.
 */
final class TemporalHistoryCursorCloseEventTest extends TestCase
{
    private TemporalConnection $connection;

    protected function setUp(): void
    {
        $this->connection = new TemporalConnection(
            target: 'localhost:7233',
            namespace: 'default',
        );
    }

    public function testReturnsNullWhenWorkflowNotFound(): void
    {
        $client = FakeWorkflowServiceClient::withStatus(code: 5, details: 'workflow execution not found');
        $cursor = new TemporalHistoryCursor($client, $this->connection);
        $execution = new WorkflowExecution(['workflow_id' => 'durable-test-1']);

        $event = $cursor->closeEvent($execution);

        self::assertNull($event);
    }

    public function testReturnsNullWhenHistoryHasNoCloseEvent(): void
    {
        $response = new GetWorkflowExecutionHistoryResponse();
        $response->setHistory(new History());

        $client = FakeWorkflowServiceClient::withResponse($response);
        $cursor = new TemporalHistoryCursor($client, $this->connection);
        $execution = new WorkflowExecution(['workflow_id' => 'durable-test-2']);

        $event = $cursor->closeEvent($execution);

        self::assertNull($event);
    }

    public function testReturnsCompletedEvent(): void
    {
        $historyEvent = new HistoryEvent();
        $historyEvent->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED);

        $history = new History();
        $history->setEvents([$historyEvent]);

        $response = new GetWorkflowExecutionHistoryResponse();
        $response->setHistory($history);

        $client = FakeWorkflowServiceClient::withResponse($response);
        $cursor = new TemporalHistoryCursor($client, $this->connection);
        $execution = new WorkflowExecution(['workflow_id' => 'durable-test-3']);

        $event = $cursor->closeEvent($execution);

        self::assertNotNull($event);
        self::assertSame(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED, $event->getEventType());
    }

    public function testReturnsFailedEvent(): void
    {
        $historyEvent = new HistoryEvent();
        $historyEvent->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED);

        $history = new History();
        $history->setEvents([$historyEvent]);

        $response = new GetWorkflowExecutionHistoryResponse();
        $response->setHistory($history);

        $client = FakeWorkflowServiceClient::withResponse($response);
        $cursor = new TemporalHistoryCursor($client, $this->connection);
        $execution = new WorkflowExecution(['workflow_id' => 'durable-test-4']);

        $event = $cursor->closeEvent($execution);

        self::assertNotNull($event);
        self::assertSame(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED, $event->getEventType());
    }

    public function testThrowsOnUnexpectedGrpcError(): void
    {
        $client = FakeWorkflowServiceClient::withStatus(code: 14, details: 'unavailable');
        $cursor = new TemporalHistoryCursor($client, $this->connection);
        $execution = new WorkflowExecution(['workflow_id' => 'durable-test-5']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Temporal gRPC error \[14\]/');

        $cursor->closeEvent($execution);
    }

    public function testIgnoresNonCloseEvents(): void
    {
        $openEvent = new HistoryEvent();
        $openEvent->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED);

        $history = new History();
        $history->setEvents([$openEvent]);

        $response = new GetWorkflowExecutionHistoryResponse();
        $response->setHistory($history);

        $client = FakeWorkflowServiceClient::withResponse($response);
        $cursor = new TemporalHistoryCursor($client, $this->connection);
        $execution = new WorkflowExecution(['workflow_id' => 'durable-test-6']);

        $event = $cursor->closeEvent($execution);

        self::assertNull($event);
    }
}

/**
 * Test double for the bridge's client contract: one programmed answer for every RPC, either a
 * response or a gRPC status to fail with. No channel, no extension.
 *
 * @internal
 */
final class FakeWorkflowServiceClient extends AbstractWorkflowServiceClient
{
    private function __construct(
        private readonly ?Message $response,
        private readonly int $code,
        private readonly string $details,
    ) {
    }

    public static function withResponse(Message $response): self
    {
        return new self($response, 0, '');
    }

    public static function withStatus(int $code, string $details = ''): self
    {
        return new self(null, $code, $details);
    }

    protected function call(string $rpc, Message $request, string $responseClass, array $metadata, array $options): Message
    {
        if (0 !== $this->code || null === $this->response) {
            throw new \RuntimeException(\sprintf('Temporal gRPC error [%d]: %s', $this->code, $this->details), $this->code);
        }

        return $this->response;
    }
}
