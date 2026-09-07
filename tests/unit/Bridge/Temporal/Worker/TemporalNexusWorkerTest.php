<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceNexusRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\Serving\NexusHandlerErrorType;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\Nexus\Serving\NexusOperationResponse;
use Grpc\UnaryCall;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Nexus\V1\CancelOperationRequest;
use Temporal\Api\Nexus\V1\Request as NexusRequest;
use Temporal\Api\Nexus\V1\StartOperationRequest;
use Temporal\Api\Workflowservice\V1\PollNexusTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RequestCancelWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\RequestCancelWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskCompletedResponse;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskFailedRequest;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskFailedResponse;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * The worker seen from the wire: what it sends to the server for each shape of answer.
 *
 * The gRPC client is simulated, so nothing here depends on a server — this is the level where the
 * four branches (empty, immediate, deferred, refused) read at a glance. What these tests cannot
 * prove is that the server accepts what is sent to it: that is the job of
 * {@see \integration\Temporal\NexusServedOperationTest}.
 */
#[RequiresPhpExtension('grpc')]
final class TemporalNexusWorkerTest extends TestCase
{
    private WorkflowServiceClient $grpc;

    protected function setUp(): void
    {
        $this->grpc = $this->createMock(WorkflowServiceClient::class);
    }

    public function testAnEmptyPollDoesNothingAtAll(): void
    {
        // §1.2: an empty queue returns an empty token after ~11 s, and that is a success.
        // Treating it as an error would make the loop spin empty while shouting.
        $this->grpc->method('PollNexusTaskQueue')->willReturn($this->call(new PollNexusTaskQueueResponse()));
        $this->grpc->expects($this->never())->method('RespondNexusTaskCompleted');
        $this->grpc->expects($this->never())->method('RespondNexusTaskFailed');
        $this->grpc->expects($this->never())->method('StartWorkflowExecution');

        $this->worker(NexusOperationRegistry::routedBy('temporal'))->pollOnce();
    }

    public function testAnImmediateAnswerIsSentAsASyncSuccess(): void
    {
        $registry = NexusOperationRegistry::routedBy('temporal');
        $registry->register(
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            static fn(mixed $payload): NexusOperationResponse => NexusOperationResponse::completed(['charged' => $payload['amount']]),
        );

        $this->grpc->method('PollNexusTaskQueue')->willReturn($this->call($this->startTask(['amount' => 10])));
        $this->grpc->expects($this->never())->method('StartWorkflowExecution');

        $sent = null;
        $this->grpc->expects($this->once())->method('RespondNexusTaskCompleted')
            ->willReturnCallback(function (RespondNexusTaskCompletedRequest $request) use (&$sent): UnaryCall {
                $sent = $request;

                return $this->call(new RespondNexusTaskCompletedResponse());
            });

        $this->worker($registry)->pollOnce();

        self::assertSame('jeton-de-tache', $sent?->getTaskToken());
        $sync = $sent?->getResponse()?->getStartOperation()?->getSyncSuccess();
        self::assertNotNull($sync, 'An immediate answer must leave as a syncSuccess.');
        self::assertSame(['charged' => 10], JsonPlainPayload::decode($sync->getPayload()));
    }

    public function testADeferredAnswerStartsTheWorkflowCarryingTheTasksCallbackBeforeAnswering(): void
    {
        // §3.1, measured: it is the callback attached to the workflow that settles the
        // operation, and `completion_callbacks` is only set at start. Answering first would leave
        // the caller waiting for an outcome that would never arrive.
        $registry = NexusOperationRegistry::routedBy('temporal');
        $registry->register(
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            static fn(): NexusOperationResponse => NexusOperationResponse::fulfilledByWorkflow('ChargeWorkflow', ['amount' => 10], 'charge-1'),
        );

        $this->grpc->method('PollNexusTaskQueue')->willReturn($this->call($this->startTask(['amount' => 10])));

        $order = [];
        $started = null;
        $this->grpc->expects($this->once())->method('StartWorkflowExecution')
            ->willReturnCallback(function (StartWorkflowExecutionRequest $request) use (&$order, &$started): UnaryCall {
                $order[] = 'start';
                $started = $request;

                return $this->call(new StartWorkflowExecutionResponse());
            });

        $answered = null;
        $this->grpc->expects($this->once())->method('RespondNexusTaskCompleted')
            ->willReturnCallback(function (RespondNexusTaskCompletedRequest $request) use (&$order, &$answered): UnaryCall {
                $order[] = 'respond';
                $answered = $request;

                return $this->call(new RespondNexusTaskCompletedResponse());
            });

        $this->worker($registry)->pollOnce();

        self::assertSame(['start', 'respond'], $order, 'The workflow must start before the answer.');

        self::assertSame('charge-1', $started?->getWorkflowId());
        self::assertSame('ChargeWorkflow', $started?->getWorkflowType()?->getName());
        $callbacks = $started?->getCompletionCallbacks();
        self::assertNotNull($callbacks);
        self::assertCount(1, $callbacks, 'Without an attached callback, the caller never learns the outcome.');
        self::assertSame('temporal://system', $callbacks[0]->getNexus()?->getUrl());

        $async = $answered?->getResponse()?->getStartOperation()?->getAsyncSuccess();
        self::assertNotNull($async, 'A deferred answer must leave as an asyncSuccess.');
        self::assertSame('charge-1', $async->getOperationToken());
    }

    public function testAnOperationNobodyServesIsRefusedWithoutRetry(): void
    {
        // §2.4 and §1b.3: NOT_IMPLEMENTED is terminal. Made retryable, the same operation would
        // come back every ~9 s for its whole budget, for the same answer.
        $this->grpc->method('PollNexusTaskQueue')->willReturn($this->call($this->startTask([])));

        $sent = null;
        $this->grpc->expects($this->once())->method('RespondNexusTaskFailed')
            ->willReturnCallback(function (RespondNexusTaskFailedRequest $request) use (&$sent): UnaryCall {
                $sent = $request;

                return $this->call(new RespondNexusTaskFailedResponse());
            });

        $this->worker(NexusOperationRegistry::routedBy('temporal'))->pollOnce();

        self::assertSame(NexusHandlerErrorType::NotImplemented->value, $sent?->getError()?->getErrorType());
        self::assertSame(
            \Temporal\Api\Enums\V1\NexusHandlerErrorRetryBehavior::NEXUS_HANDLER_ERROR_RETRY_BEHAVIOR_NON_RETRYABLE,
            $sent?->getError()?->getRetryBehavior(),
        );
    }

    public function testAHandlerThatRaisesIsReportedAsRetryableInternal(): void
    {
        // What every other SDK does: an ordinary exception counts as INTERNAL. A handler that
        // wants a final refusal must say so with its type.
        $registry = NexusOperationRegistry::routedBy('temporal');
        $registry->register(
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            static fn(): NexusOperationResponse => throw new \RuntimeException('la base est tombée'),
        );

        $this->grpc->method('PollNexusTaskQueue')->willReturn($this->call($this->startTask([])));

        $sent = null;
        $this->grpc->expects($this->once())->method('RespondNexusTaskFailed')
            ->willReturnCallback(function (RespondNexusTaskFailedRequest $request) use (&$sent): UnaryCall {
                $sent = $request;

                return $this->call(new RespondNexusTaskFailedResponse());
            });

        $this->worker($registry)->pollOnce();

        self::assertSame(NexusHandlerErrorType::Internal->value, $sent?->getError()?->getErrorType());
        self::assertSame(
            \Temporal\Api\Enums\V1\NexusHandlerErrorRetryBehavior::NEXUS_HANDLER_ERROR_RETRY_BEHAVIOR_RETRYABLE,
            $sent?->getError()?->getRetryBehavior(),
        );
        self::assertStringContainsString('la base est tombée', (string) $sent?->getError()?->getFailure()?->getMessage());
    }

    public function testACancellationCancelsTheWorkflowNamedByTheToken(): void
    {
        // Probe §4: the cancellation task names the token returned at start, and that token is
        // the workflow this worker started. Cancelling the operation means cancelling that
        // workflow.
        $this->grpc->method('PollNexusTaskQueue')->willReturn($this->call($this->cancelTask('charge-1')));

        $cancelled = null;
        $this->grpc->expects($this->once())->method('RequestCancelWorkflowExecution')
            ->willReturnCallback(function (RequestCancelWorkflowExecutionRequest $request) use (&$cancelled): UnaryCall {
                $cancelled = $request;

                return $this->call(new RequestCancelWorkflowExecutionResponse());
            });

        $answered = null;
        $this->grpc->expects($this->once())->method('RespondNexusTaskCompleted')
            ->willReturnCallback(function (RespondNexusTaskCompletedRequest $request) use (&$answered): UnaryCall {
                $answered = $request;

                return $this->call(new RespondNexusTaskCompletedResponse());
            });

        $this->worker(NexusOperationRegistry::routedBy('temporal'))->pollOnce();

        self::assertSame('charge-1', $cancelled?->getWorkflowExecution()?->getWorkflowId());
        self::assertNotNull(
            $answered?->getResponse()?->getCancelOperation(),
            'The cancellation must be acknowledged, otherwise the task comes back every ~9 s.',
        );
    }

    public function testAWorkflowThatAlreadyEndedStillAcknowledgesTheCancellation(): void
    {
        // The workflow may have ended between the request and us. The operation is already
        // settled: insisting would ask for the task again for the whole budget, for nothing.
        $this->grpc->method('PollNexusTaskQueue')->willReturn($this->call($this->cancelTask('charge-1')));
        $this->grpc->method('RequestCancelWorkflowExecution')
            ->willReturn($this->call(null, \Grpc\STATUS_NOT_FOUND));

        $this->grpc->expects($this->once())->method('RespondNexusTaskCompleted')
            ->willReturn($this->call(new RespondNexusTaskCompletedResponse()));
        $this->grpc->expects($this->never())->method('RespondNexusTaskFailed');

        $this->worker(NexusOperationRegistry::routedBy('temporal'))->pollOnce();
    }

    public function testACancellationWithoutATokenIsRefusedTerminally(): void
    {
        $this->grpc->method('PollNexusTaskQueue')->willReturn($this->call($this->cancelTask('')));
        $this->grpc->expects($this->never())->method('RequestCancelWorkflowExecution');

        $sent = null;
        $this->grpc->expects($this->once())->method('RespondNexusTaskFailed')
            ->willReturnCallback(function (RespondNexusTaskFailedRequest $request) use (&$sent): UnaryCall {
                $sent = $request;

                return $this->call(new RespondNexusTaskFailedResponse());
            });

        $this->worker(NexusOperationRegistry::routedBy('temporal'))->pollOnce();

        self::assertSame(NexusHandlerErrorType::BadRequest->value, $sent?->getError()?->getErrorType());
    }

    private function cancelTask(string $token): PollNexusTaskQueueResponse
    {
        $cancel = new CancelOperationRequest();
        $cancel->setService('billing');
        $cancel->setOperation('charge');
        $cancel->setOperationToken($token);

        $request = new NexusRequest();
        $request->setCancelOperation($cancel);

        $task = new PollNexusTaskQueueResponse();
        $task->setTaskToken('jeton-de-tache');
        $task->setRequest($request);

        return $task;
    }

    private function worker(NexusOperationRegistry $registry): TemporalNexusWorker
    {
        return new TemporalNexusWorker(
            new WorkflowServiceNexusRpc($this->grpc),
            new TemporalConnection(target: 'localhost:7233', namespace: 'test'),
            $registry,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function startTask(array $payload): PollNexusTaskQueueResponse
    {
        $start = new StartOperationRequest();
        $start->setService('billing');
        $start->setOperation('charge');
        $start->setCallback('temporal://system');
        $start->setPayload(JsonPlainPayload::encode($payload));

        $request = new NexusRequest();
        $request->setStartOperation($start);

        $task = new PollNexusTaskQueueResponse();
        $task->setTaskToken('jeton-de-tache');
        $task->setRequest($request);

        return $task;
    }

    private function call(mixed $response, int $code = \Grpc\STATUS_OK): UnaryCall
    {
        $call = $this->createMock(UnaryCall::class);
        $status = new \stdClass();
        $status->code = $code;
        $status->details = '';
        $call->method('wait')->willReturn([$response, $status]);

        return $call;
    }
}
