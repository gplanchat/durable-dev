<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Tests unitaires pour {@see TemporalHistoryCursor::closeEvent()}.
 *
 * Utilise un double de test de {@see WorkflowServiceClient} (sous-classe sans gRPC réel)
 * afin de contrôler les réponses gRPC de GetWorkflowExecutionHistory.
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
 * Test double pour {@see WorkflowServiceClient} : évite toute connexion gRPC réelle.
 * Le constructeur parent n'est pas appelé, ce qui permet d'utiliser cette classe
 * dans des tests sans l'extension gRPC active.
 *
 * @internal
 */
final class FakeWorkflowServiceClient extends WorkflowServiceClient
{
    /** @var array{response: GetWorkflowExecutionHistoryResponse|null, code: int, details: string} */
    private array $programmedResponse;

    private function __construct(
        GetWorkflowExecutionHistoryResponse|null $response,
        int $code,
        string $details,
    ) {
        // Parent constructor intentionally NOT called: avoids gRPC channel creation.
        $this->programmedResponse = ['response' => $response, 'code' => $code, 'details' => $details];
    }

    public static function withResponse(GetWorkflowExecutionHistoryResponse $response): self
    {
        return new self($response, 0, '');
    }

    public static function withStatus(int $code, string $details = ''): self
    {
        return new self(null, $code, $details);
    }

    public function GetWorkflowExecutionHistory(
        $argument,
        $metadata = [],
        $options = [],
    ): FakeGrpcCall {
        $status = (object) [
            'code'    => $this->programmedResponse['code'],
            'details' => $this->programmedResponse['details'],
        ];

        return new FakeGrpcCall($this->programmedResponse['response'], $status);
    }
}

/**
 * Simule un appel gRPC unaire avec un résultat préprogrammé.
 *
 * @internal
 */
final class FakeGrpcCall
{
    public function __construct(
        private readonly mixed $response,
        private readonly object $status,
    ) {
    }

    /** @return array{0: mixed, 1: object} */
    public function wait(): array
    {
        return [$this->response, $this->status];
    }
}
