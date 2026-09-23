<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Google\Protobuf\Internal\Message;
use Temporal\Api\Workflowservice\V1 as Ws;

/**
 * The workflow and Nexus half of {@see WorkflowServiceClientInterface}: start, signal, query,
 * update, history, and the workflow and Nexus task queues.
 */
trait WorkflowRpcMethods
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

    public function DescribeWorkflowExecution(Ws\DescribeWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\DescribeWorkflowExecutionResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\DescribeWorkflowExecutionResponse::class, $metadata, $options);
    }

    public function GetWorkflowExecutionHistory(Ws\GetWorkflowExecutionHistoryRequest $request, array $metadata = [], array $options = []): Ws\GetWorkflowExecutionHistoryResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\GetWorkflowExecutionHistoryResponse::class, $metadata, $options);
    }

    public function ListWorkflowExecutions(Ws\ListWorkflowExecutionsRequest $request, array $metadata = [], array $options = []): Ws\ListWorkflowExecutionsResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\ListWorkflowExecutionsResponse::class, $metadata, $options);
    }

    public function PollNexusTaskQueue(Ws\PollNexusTaskQueueRequest $request, array $metadata = [], array $options = []): Ws\PollNexusTaskQueueResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\PollNexusTaskQueueResponse::class, $metadata, $options);
    }

    public function PollWorkflowExecutionUpdate(Ws\PollWorkflowExecutionUpdateRequest $request, array $metadata = [], array $options = []): Ws\PollWorkflowExecutionUpdateResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\PollWorkflowExecutionUpdateResponse::class, $metadata, $options);
    }

    public function PollWorkflowTaskQueue(Ws\PollWorkflowTaskQueueRequest $request, array $metadata = [], array $options = []): Ws\PollWorkflowTaskQueueResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\PollWorkflowTaskQueueResponse::class, $metadata, $options);
    }

    public function QueryWorkflow(Ws\QueryWorkflowRequest $request, array $metadata = [], array $options = []): Ws\QueryWorkflowResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\QueryWorkflowResponse::class, $metadata, $options);
    }

    public function RequestCancelWorkflowExecution(Ws\RequestCancelWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\RequestCancelWorkflowExecutionResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\RequestCancelWorkflowExecutionResponse::class, $metadata, $options);
    }

    public function RespondNexusTaskCompleted(Ws\RespondNexusTaskCompletedRequest $request, array $metadata = [], array $options = []): Ws\RespondNexusTaskCompletedResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\RespondNexusTaskCompletedResponse::class, $metadata, $options);
    }

    public function RespondNexusTaskFailed(Ws\RespondNexusTaskFailedRequest $request, array $metadata = [], array $options = []): Ws\RespondNexusTaskFailedResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\RespondNexusTaskFailedResponse::class, $metadata, $options);
    }

    public function RespondWorkflowTaskCompleted(Ws\RespondWorkflowTaskCompletedRequest $request, array $metadata = [], array $options = []): Ws\RespondWorkflowTaskCompletedResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\RespondWorkflowTaskCompletedResponse::class, $metadata, $options);
    }

    public function RespondWorkflowTaskFailed(Ws\RespondWorkflowTaskFailedRequest $request, array $metadata = [], array $options = []): Ws\RespondWorkflowTaskFailedResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\RespondWorkflowTaskFailedResponse::class, $metadata, $options);
    }

    public function SignalWithStartWorkflowExecution(Ws\SignalWithStartWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\SignalWithStartWorkflowExecutionResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\SignalWithStartWorkflowExecutionResponse::class, $metadata, $options);
    }

    public function SignalWorkflowExecution(Ws\SignalWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\SignalWorkflowExecutionResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\SignalWorkflowExecutionResponse::class, $metadata, $options);
    }

    public function TerminateWorkflowExecution(Ws\TerminateWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\TerminateWorkflowExecutionResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\TerminateWorkflowExecutionResponse::class, $metadata, $options);
    }

    public function StartWorkflowExecution(Ws\StartWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\StartWorkflowExecutionResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\StartWorkflowExecutionResponse::class, $metadata, $options);
    }

    public function UpdateWorkflowExecution(Ws\UpdateWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\UpdateWorkflowExecutionResponse
    {
        return $this->call(__FUNCTION__, $request, Ws\UpdateWorkflowExecutionResponse::class, $metadata, $options);
    }
}
