<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceNexusRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\Serving\NexusHandlerErrorType;
use Gplanchat\Durable\Nexus\Serving\NexusOperationNotHandledException;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\Nexus\Serving\NexusOperationResponse;
use Temporal\Api\Common\V1\Callback;
use Temporal\Api\Common\V1\Callback\Nexus as NexusCallback;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Nexus\V1\CancelOperationRequest;
use Temporal\Api\Nexus\V1\CancelOperationResponse;
use Temporal\Api\Nexus\V1\Failure as NexusFailure;
use Temporal\Api\Nexus\V1\HandlerError;
use Temporal\Api\Nexus\V1\Response as NexusResponse;
use Temporal\Api\Nexus\V1\StartOperationRequest;
use Temporal\Api\Nexus\V1\StartOperationResponse;
use Temporal\Api\Nexus\V1\StartOperationResponse\Async as StartOperationAsync;
use Temporal\Api\Nexus\V1\StartOperationResponse\Sync as StartOperationSync;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollNexusTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\RequestCancelWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskFailedRequest;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;

/**
 * Polls the Nexus task queue, routes to the declared handler, and answers.
 *
 * This worker shares nothing with {@see WorkflowTaskProcessor} but the "poll and answer" shape:
 * no history, no replay, no determinism, no slots. Running it through the workflow worker would
 * drag a replay engine into a path that never replays.
 *
 * Three probe measurements shape it, and none of them is a detail:
 *
 * - **§1.2** — an empty queue returns an empty token and a null request, after ~11 s. That is a
 *   success, not an error: the loop starts again.
 * - **§1.7** — two budgets. `request-timeout` (~9 s) bounds the answer to *this task*;
 *   `operation-timeout` bounds the operation. A handler that works for more than nine seconds sees
 *   its task redelivered and its work start over. That is what the deferred shape avoids.
 * - **§3.1** — what settles a deferred operation is the task's `callback`, attached to the
 *   workflow that fulfils it. Dropped, the caller stays at `NEXUS_OPERATION_STARTED` forever.
 *   Hence the order here: the workflow is started **before** answering, because
 *   `completion_callbacks` is only set at start.
 */
final readonly class TemporalNexusWorker
{
    public function __construct(
        private WorkflowServiceNexusRpc $nexusRpc,
        private TemporalConnection $connection,
        private NexusOperationRegistry $registry,
    ) {}

    /**
     * One long poll; if a task arrives, routing and answer.
     */
    public function pollOnce(): void
    {
        $request = new PollNexusTaskQueueRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setTaskQueue(new TaskQueue(['name' => $this->connection->nexusTaskQueue->name()]));
        $request->setIdentity($this->connection->identity . '-nexus');

        $task = $this->nexusRpc->pollNexusTaskQueue($request);

        $taskToken = (string) $task->getTaskToken();
        if ('' === $taskToken) {
            // §1.2: nothing to do. Treating it as an error would loop on a nominal case.
            return;
        }

        $cancel = $task->getRequest()?->getCancelOperation();
        if (null !== $cancel) {
            $this->cancelTheWorkflowCarryingTheOperation($taskToken, $cancel);

            return;
        }

        $start = $task->getRequest()?->getStartOperation();
        if (null === $start) {
            // A variant this worker does not serve. Refusing it by name is better than letting
            // the task expire in silence.
            $this->respondFailed($taskToken, NexusHandlerErrorType::NotImplemented, 'This worker serves start_operation and cancel_operation tasks only.');

            return;
        }

        $service = (string) $start->getService();
        $operation = (string) $start->getOperation();

        try {
            $response = $this->registry->dispatch(
                NexusService::named($service),
                NexusOperationName::named($operation),
                $this->decodePayload($start),
            );
        } catch (NexusOperationNotHandledException $refusal) {
            // §2.4: the answer says nobody serves it, and the loop keeps serving the rest.
            $this->respondFailed($taskToken, $refusal->type(), $refusal->getMessage());

            return;
        } catch (\Throwable $raised) {
            // §1b.3: an ordinary exception is worth INTERNAL, hence retryable — as in every
            // other SDK. A handler that wants a definitive refusal says so with its type.
            $this->respondFailed($taskToken, NexusHandlerErrorType::Internal, $raised->getMessage());

            return;
        }

        if ($response->isImmediate) {
            $this->respondCompletedNow($taskToken, $response->result);

            return;
        }

        $this->respondFulfilledByWorkflow($taskToken, $start, $response);
    }

    /**
     * Cancelling the operation means cancelling the workflow that carries it.
     *
     * The §4 probe measured it in both halves. §1.5 had seen the negative one: as long as the
     * operation has not started, no task arrives here — there is nothing to cancel. The positive
     * one reads now that an operation can start asynchronously: the task arrives, and it **names
     * the token returned at start**. That token is the id of the workflow this worker started, so
     * the task hands us exactly the handle we need.
     *
     * The handler is not solicited again, and that is no gap: what carries the operation is a
     * workflow, and a workflow already observes its own cancellation — with its compensations. A
     * handler hook would duplicate that path without adding anything to it.
     */
    private function cancelTheWorkflowCarryingTheOperation(string $taskToken, CancelOperationRequest $cancel): void
    {
        $token = (string) $cancel->getOperationToken();
        if ('' === $token) {
            $this->respondFailed($taskToken, NexusHandlerErrorType::BadRequest, 'A cancellation task carried no operation token.');

            return;
        }

        $request = new RequestCancelWorkflowExecutionRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $token]));
        $request->setIdentity($this->connection->identity . '-nexus');
        $request->setRequestId(bin2hex(random_bytes(8)));
        $request->setReason('Nexus operation cancelled by its caller.');

        try {
            $this->nexusRpc->requestCancelWorkflowExecution($request);
        } catch (\RuntimeException $error) {
            // The workflow may have ended between the request and us. That is not a handler
            // error: the operation is already settled, and insisting would have it asked for
            // again every ~9 s for nothing.
            $this->respondCancelled($taskToken);

            return;
        }

        $this->respondCancelled($taskToken);
    }

    private function respondCancelled(string $taskToken): void
    {
        $response = new NexusResponse();
        $response->setCancelOperation(new CancelOperationResponse());

        $request = new RespondNexusTaskCompletedRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setIdentity($this->connection->identity . '-nexus');
        $request->setTaskToken($taskToken);
        $request->setResponse($response);

        $this->nexusRpc->respondNexusTaskCompleted($request);
    }

    private function decodePayload(StartOperationRequest $start): mixed
    {
        $payload = $start->getPayload();

        return null === $payload ? null : JsonPlainPayload::decode($payload);
    }

    private function respondCompletedNow(string $taskToken, mixed $result): void
    {
        $sync = new StartOperationSync();
        $sync->setPayload(JsonPlainPayload::encode($result));

        $start = new StartOperationResponse();
        $start->setSyncSuccess($sync);

        $this->respondCompleted($taskToken, $start);
    }

    private function respondFulfilledByWorkflow(
        string $taskToken,
        StartOperationRequest $task,
        NexusOperationResponse $response,
    ): void {
        $workflowId = $response->workflowId ?? \sprintf('nexus-%s', bin2hex(random_bytes(8)));

        // The order matters: `completion_callbacks` is only set at start (§3.1). Answering first
        // and starting afterwards would leave the caller waiting for an outcome that would never
        // arrive, with nothing to signal it.
        $nexusCallback = new NexusCallback();
        $nexusCallback->setUrl((string) $task->getCallback());
        // `getCallbackHeader()` always returns a MapField, empty if need be: there is no "no
        // header" case to tell apart, and copying it as-is is what preserves what the server put
        // in it.
        $nexusCallback->setHeader($task->getCallbackHeader());
        $callback = new Callback();
        $callback->setNexus($nexusCallback);

        $start = new StartWorkflowExecutionRequest();
        $start->setNamespace($this->connection->namespace->name());
        $start->setWorkflowId($workflowId);
        $start->setWorkflowType((new WorkflowType())->setName((string) $response->workflowType));
        $start->setTaskQueue(new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]));
        $start->setIdentity($this->connection->identity . '-nexus');
        $start->setRequestId(bin2hex(random_bytes(8)));
        $start->setInput((new Payloads())->setPayloads([JsonPlainPayload::encode($response->workflowInput)]));
        $start->setCompletionCallbacks([$callback]);

        $this->nexusRpc->startWorkflowExecution($start);

        $async = new StartOperationAsync();
        $async->setOperationToken($workflowId);

        $operation = new StartOperationResponse();
        $operation->setAsyncSuccess($async);

        $this->respondCompleted($taskToken, $operation);
    }

    private function respondCompleted(string $taskToken, StartOperationResponse $start): void
    {
        $response = new NexusResponse();
        $response->setStartOperation($start);

        $request = new RespondNexusTaskCompletedRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setIdentity($this->connection->identity . '-nexus');
        $request->setTaskToken($taskToken);
        $request->setResponse($response);

        $this->nexusRpc->respondNexusTaskCompleted($request);
    }

    private function respondFailed(string $taskToken, NexusHandlerErrorType $type, string $message): void
    {
        $failure = new NexusFailure();
        $failure->setMessage($message);

        $error = new HandlerError();
        $error->setErrorType($type->value);
        $error->setFailure($failure);
        $error->setRetryBehavior($type->isRetryable()
            ? \Temporal\Api\Enums\V1\NexusHandlerErrorRetryBehavior::NEXUS_HANDLER_ERROR_RETRY_BEHAVIOR_RETRYABLE
            : \Temporal\Api\Enums\V1\NexusHandlerErrorRetryBehavior::NEXUS_HANDLER_ERROR_RETRY_BEHAVIOR_NON_RETRYABLE);

        $request = new RespondNexusTaskFailedRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setIdentity($this->connection->identity . '-nexus');
        $request->setTaskToken($taskToken);
        $request->setError($error);

        $this->nexusRpc->respondNexusTaskFailed($request);
    }
}
