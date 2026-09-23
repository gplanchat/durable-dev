<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Google\Protobuf\Internal\Message;
use Temporal\Api\Workflowservice\V1 as Ws;

/**
 * The activity half of {@see WorkflowServiceClientInterface}: task polling, heartbeats,
 * completions, and the standalone activity execution RPCs.
 */
trait ActivityRpcMethods
{
    /**
     * @template T of Message
     *
     * @param class-string<T>      $responseClass
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $options
     *
     * @return T
     */
    abstract protected function call(string $rpc, Message $request, string $responseClass, array $metadata, array $options): Message;

    public function CountActivityExecutions(Ws\CountActivityExecutionsRequest $request, array $metadata = [], array $options = []): Ws\CountActivityExecutionsResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\CountActivityExecutionsResponse::class, $metadata, $options);
    }

    public function DeleteActivityExecution(Ws\DeleteActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\DeleteActivityExecutionResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\DeleteActivityExecutionResponse::class, $metadata, $options);
    }

    public function DescribeActivityExecution(Ws\DescribeActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\DescribeActivityExecutionResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\DescribeActivityExecutionResponse::class, $metadata, $options);
    }

    public function ListActivityExecutions(Ws\ListActivityExecutionsRequest $request, array $metadata = [], array $options = []): Ws\ListActivityExecutionsResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\ListActivityExecutionsResponse::class, $metadata, $options);
    }

    public function PauseActivity(Ws\PauseActivityRequest $request, array $metadata = [], array $options = []): Ws\PauseActivityResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\PauseActivityResponse::class, $metadata, $options);
    }

    public function PollActivityExecution(Ws\PollActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\PollActivityExecutionResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\PollActivityExecutionResponse::class, $metadata, $options);
    }

    public function PollActivityTaskQueue(Ws\PollActivityTaskQueueRequest $request, array $metadata = [], array $options = []): Ws\PollActivityTaskQueueResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\PollActivityTaskQueueResponse::class, $metadata, $options);
    }

    public function RecordActivityTaskHeartbeat(Ws\RecordActivityTaskHeartbeatRequest $request, array $metadata = [], array $options = []): Ws\RecordActivityTaskHeartbeatResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\RecordActivityTaskHeartbeatResponse::class, $metadata, $options);
    }

    public function RecordActivityTaskHeartbeatById(Ws\RecordActivityTaskHeartbeatByIdRequest $request, array $metadata = [], array $options = []): Ws\RecordActivityTaskHeartbeatByIdResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\RecordActivityTaskHeartbeatByIdResponse::class, $metadata, $options);
    }

    public function RequestCancelActivityExecution(Ws\RequestCancelActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\RequestCancelActivityExecutionResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\RequestCancelActivityExecutionResponse::class, $metadata, $options);
    }

    public function ResetActivity(Ws\ResetActivityRequest $request, array $metadata = [], array $options = []): Ws\ResetActivityResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\ResetActivityResponse::class, $metadata, $options);
    }

    public function RespondActivityTaskCanceled(Ws\RespondActivityTaskCanceledRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskCanceledResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\RespondActivityTaskCanceledResponse::class, $metadata, $options);
    }

    public function RespondActivityTaskCanceledById(Ws\RespondActivityTaskCanceledByIdRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskCanceledByIdResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\RespondActivityTaskCanceledByIdResponse::class, $metadata, $options);
    }

    public function RespondActivityTaskCompleted(Ws\RespondActivityTaskCompletedRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskCompletedResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\RespondActivityTaskCompletedResponse::class, $metadata, $options);
    }

    public function RespondActivityTaskCompletedById(Ws\RespondActivityTaskCompletedByIdRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskCompletedByIdResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\RespondActivityTaskCompletedByIdResponse::class, $metadata, $options);
    }

    public function RespondActivityTaskFailed(Ws\RespondActivityTaskFailedRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskFailedResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\RespondActivityTaskFailedResponse::class, $metadata, $options);
    }

    public function RespondActivityTaskFailedById(Ws\RespondActivityTaskFailedByIdRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskFailedByIdResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\RespondActivityTaskFailedByIdResponse::class, $metadata, $options);
    }

    public function StartActivityExecution(Ws\StartActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\StartActivityExecutionResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\StartActivityExecutionResponse::class, $metadata, $options);
    }

    public function TerminateActivityExecution(Ws\TerminateActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\TerminateActivityExecutionResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\TerminateActivityExecutionResponse::class, $metadata, $options);
    }

    public function UnpauseActivity(Ws\UnpauseActivityRequest $request, array $metadata = [], array $options = []): Ws\UnpauseActivityResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\UnpauseActivityResponse::class, $metadata, $options);
    }

    public function UpdateActivityOptions(Ws\UpdateActivityOptionsRequest $request, array $metadata = [], array $options = []): Ws\UpdateActivityOptionsResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\UpdateActivityOptionsResponse::class, $metadata, $options);
    }
}
