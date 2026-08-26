<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceActivityRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Grpc\UnaryCall;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCompletedResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * The activity worker must tolerate a stale task: responding for a task whose
 * workflow/activity already closed or timed out yields gRPC NOT_FOUND (5),
 * which is benign and must not crash the poll loop. Any other gRPC error still
 * propagates.
 *
 * Strategy: seed the event store with a terminal ActivityCompleted so pollOnce()
 * takes the "already terminal" shortcut straight to RespondActivityTaskCompleted,
 * and control that RPC's gRPC status via a mocked WorkflowServiceClient.
 */
#[RequiresPhpExtension('grpc')]
final class TemporalActivityWorkerTest extends TestCase
{
    private WorkflowServiceClient $grpcClient;
    private InMemoryEventStore $eventStore;

    protected function setUp(): void
    {
        $this->grpcClient = $this->createMock(WorkflowServiceClient::class);
        $this->eventStore = new InMemoryEventStore();
    }

    public function testStaleTaskOnRespondIsSwallowed(): void
    {
        $this->arrangeTerminalActivity('exec-1', 'act-1');
        $this->grpcClient->method('PollActivityTaskQueue')
            ->willReturn($this->unaryCall($this->pollFor('exec-1', 'act-1'), \Grpc\STATUS_OK));
        $this->grpcClient->expects($this->once())
            ->method('RespondActivityTaskCompleted')
            ->willReturn($this->unaryCall(null, \Grpc\STATUS_NOT_FOUND));

        // No exception thrown + RespondActivityTaskCompleted called once (mock
        // expectation) is the assertion.
        $this->makeWorker()->pollOnce();
    }

    public function testNonStaleGrpcErrorOnRespondPropagates(): void
    {
        $this->arrangeTerminalActivity('exec-2', 'act-2');
        $this->grpcClient->method('PollActivityTaskQueue')
            ->willReturn($this->unaryCall($this->pollFor('exec-2', 'act-2'), \Grpc\STATUS_OK));
        $this->grpcClient->method('RespondActivityTaskCompleted')
            ->willReturn($this->unaryCall(null, \Grpc\STATUS_UNAVAILABLE));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(\Grpc\STATUS_UNAVAILABLE);
        $this->makeWorker()->pollOnce();
    }

    public function testSuccessfulRespondDoesNotThrow(): void
    {
        $this->arrangeTerminalActivity('exec-3', 'act-3');
        $this->grpcClient->method('PollActivityTaskQueue')
            ->willReturn($this->unaryCall($this->pollFor('exec-3', 'act-3'), \Grpc\STATUS_OK));
        $this->grpcClient->method('RespondActivityTaskCompleted')
            ->willReturn($this->unaryCall(new RespondActivityTaskCompletedResponse(), \Grpc\STATUS_OK));

        $this->makeWorker()->pollOnce();

        $this->expectNotToPerformAssertions();
    }

    public function testEmptyPollDoesNotRespond(): void
    {
        $empty = new PollActivityTaskQueueResponse();
        $empty->setTaskToken('');
        $this->grpcClient->method('PollActivityTaskQueue')
            ->willReturn($this->unaryCall($empty, \Grpc\STATUS_OK));
        $this->grpcClient->expects($this->never())->method('RespondActivityTaskCompleted');

        // The never() mock expectation is the assertion.
        $this->makeWorker()->pollOnce();
    }

    // -------------------------------------------------------------------------

    private function makeWorker(): TemporalActivityWorker
    {
        $connection = new TemporalConnection('localhost:7233', 'test-namespace');
        $processor = new ActivityMessageProcessor(
            $this->eventStore,
            new NoopActivityTransport(),
            new RegistryActivityExecutor(),
            new NullWorkflowResumeDispatcher(),
            $this->createMock(ActivityHeartbeatSenderInterface::class),
        );

        return new TemporalActivityWorker(
            new WorkflowServiceActivityRpc($this->grpcClient),
            $connection,
            $processor,
            $this->eventStore,
            $this->createMock(ActivityHeartbeatSenderInterface::class),
        );
    }

    private function arrangeTerminalActivity(string $executionId, string $activityId): void
    {
        $this->eventStore->append(new ActivityCompleted($executionId, $activityId, 'result'));
    }

    private function pollFor(string $executionId, string $activityId): PollActivityTaskQueueResponse
    {
        $payloads = new Payloads();
        $payloads->setPayloads([JsonPlainPayload::encode([
            'executionId' => $executionId,
            'activityId' => $activityId,
            'activityName' => 'sso_delete_user',
        ])]);

        $poll = new PollActivityTaskQueueResponse();
        $poll->setTaskToken('task-token');
        $poll->setInput($payloads);

        return $poll;
    }

    private function unaryCall(?object $response, int $code): UnaryCall
    {
        $status = new \stdClass();
        $status->code = $code;
        $status->details = \Grpc\STATUS_NOT_FOUND === $code
            ? 'invalid activityID or activity already timed out or invoking workflow is completed'
            : 'gRPC failure';

        $call = $this->createMock(UnaryCall::class);
        $call->method('wait')->willReturn([$response, $status]);

        return $call;
    }
}
