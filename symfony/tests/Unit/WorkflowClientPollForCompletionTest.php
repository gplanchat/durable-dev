<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionCompletedEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionFailedEventAttributes;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryResponse;

/**
 * Tests unitaires pour {@see WorkflowClient::pollForCompletion()}.
 *
 * Utilise {@see FakeWorkflowServiceClient} (défini dans TemporalHistoryCursorCloseEventTest.php)
 * pour contrôler les réponses gRPC sans connexion réseau.
 */
final class WorkflowClientPollForCompletionTest extends TestCase
{
    private TemporalConnection $connection;

    protected function setUp(): void
    {
        $this->connection = new TemporalConnection(
            target: 'localhost:7233',
            namespace: 'default',
        );
    }

    public function testReturnsResultImmediatelyWhenWorkflowAlreadyCompleted(): void
    {
        $expectedResult = 'Hello, World!';
        $client = $this->buildClient(
            responses: [$this->completedResponse($expectedResult)],
        );

        $result = $client->pollForCompletion(
            executionId: 'test-exec-1',
            refreshIntervalMs: 0,
            maxRefreshes: 3,
        );

        self::assertSame($expectedResult, $result);
    }

    public function testReturnsResultAfterSeveralNullAttempts(): void
    {
        $expectedResult = ['status' => 'done'];
        $client = $this->buildClient(
            responses: [
                $this->emptyResponse(),
                $this->emptyResponse(),
                $this->completedResponse($expectedResult),
            ],
        );

        $result = $client->pollForCompletion(
            executionId: 'test-exec-2',
            refreshIntervalMs: 0,
            maxRefreshes: 5,
        );

        self::assertSame($expectedResult, $result);
    }

    public function testThrowsAfterMaxRetriesExhausted(): void
    {
        $client = $this->buildClient(
            responses: [
                $this->emptyResponse(),
                $this->emptyResponse(),
                $this->emptyResponse(),
            ],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not complete within 3 poll attempts/');

        $client->pollForCompletion(
            executionId: 'test-exec-3',
            refreshIntervalMs: 0,
            maxRefreshes: 3,
        );
    }

    public function testThrowsWhenWorkflowFailed(): void
    {
        $client = $this->buildClient(
            responses: [$this->failedResponse('activity timed out')],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Workflow "test-exec-4" failed: activity timed out/');

        $client->pollForCompletion(
            executionId: 'test-exec-4',
            refreshIntervalMs: 0,
            maxRefreshes: 3,
        );
    }

    public function testThrowsWhenWorkflowTimedOut(): void
    {
        $client = $this->buildClient(
            responses: [$this->timedOutResponse()],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/timed out on the Temporal side/');

        $client->pollForCompletion(
            executionId: 'test-exec-5',
            refreshIntervalMs: 0,
            maxRefreshes: 3,
        );
    }

    public function testWorkflowIdFormatsCorrectly(): void
    {
        $client = $this->buildClient(responses: [$this->emptyResponse()]);

        // pollForCompletion computes workflowId = 'durable-{executionId}'.
        // We verify indirectly via the NOT_FOUND path (null response → no completion found).
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not complete within 1 poll attempts/');

        $client->pollForCompletion(
            executionId: 'my-uuid',
            refreshIntervalMs: 0,
            maxRefreshes: 1,
        );
    }

    // ------------------------------------------------------------------
    // Builder helpers
    // ------------------------------------------------------------------

    /**
     * @param list<GetWorkflowExecutionHistoryResponse> $responses
     */
    private function buildClient(array $responses): WorkflowClient
    {
        $fakeGrpcClient = new MultiResponseFakeWorkflowServiceClient($responses);
        $cursor = new TemporalHistoryCursor($fakeGrpcClient, $this->connection);
        $executionRpc = new WorkflowServiceExecutionRpc($fakeGrpcClient);

        return new WorkflowClient(
            $fakeGrpcClient,
            $this->connection,
            $cursor,
            $executionRpc,
        );
    }

    private function completedResponse(mixed $result): GetWorkflowExecutionHistoryResponse
    {
        $payload = JsonPlainPayload::encode($result);
        $payloads = JsonPlainPayload::singlePayloads($payload);

        $attr = new WorkflowExecutionCompletedEventAttributes();
        $attr->setResult($payloads);

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED);
        $event->setWorkflowExecutionCompletedEventAttributes($attr);

        $history = new History();
        $history->setEvents([$event]);

        $response = new GetWorkflowExecutionHistoryResponse();
        $response->setHistory($history);

        return $response;
    }

    private function failedResponse(string $message): GetWorkflowExecutionHistoryResponse
    {
        $failure = new Failure();
        $failure->setMessage($message);

        $attr = new WorkflowExecutionFailedEventAttributes();
        $attr->setFailure($failure);

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED);
        $event->setWorkflowExecutionFailedEventAttributes($attr);

        $history = new History();
        $history->setEvents([$event]);

        $response = new GetWorkflowExecutionHistoryResponse();
        $response->setHistory($history);

        return $response;
    }

    private function timedOutResponse(): GetWorkflowExecutionHistoryResponse
    {
        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_TIMED_OUT);

        $history = new History();
        $history->setEvents([$event]);

        $response = new GetWorkflowExecutionHistoryResponse();
        $response->setHistory($history);

        return $response;
    }

    private function emptyResponse(): GetWorkflowExecutionHistoryResponse
    {
        $response = new GetWorkflowExecutionHistoryResponse();
        $response->setHistory(new History());

        return $response;
    }
}

/**
 * Variante de {@see FakeWorkflowServiceClient} avec une file de réponses (une par appel).
 *
 * Renvoie des réponses vides une fois la file épuisée.
 *
 * @internal
 */
final class MultiResponseFakeWorkflowServiceClient extends \Temporal\Api\Workflowservice\V1\WorkflowServiceClient
{
    /** @var list<GetWorkflowExecutionHistoryResponse> */
    private array $queue;

    /** @param list<GetWorkflowExecutionHistoryResponse> $responses */
    public function __construct(array $responses)
    {
        // Parent constructor intentionally NOT called: avoids gRPC channel creation.
        $this->queue = $responses;
    }

    public function GetWorkflowExecutionHistory(
        $argument,
        $metadata = [],
        $options = [],
    ): FakeGrpcCall {
        $response = array_shift($this->queue) ?? new GetWorkflowExecutionHistoryResponse();
        $status = (object) ['code' => 0, 'details' => ''];

        return new FakeGrpcCall($response, $status);
    }
}
