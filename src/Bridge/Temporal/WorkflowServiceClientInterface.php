<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Temporal\Api\Workflowservice\V1 as Ws;

/**
 * The WorkflowService RPCs the bridge uses, one blocking method per RPC.
 *
 * The generated gRPC stub returns a call handle to wait on; this contract returns the response
 * and throws on any non-OK status, so a transport that is not ext-grpc (curl over HTTP/2, the
 * JSON gateway) can stand in for it. A failure is a \RuntimeException whose **code is the gRPC
 * status code**: NOT_FOUND (5) is benign on some RespondActivityTask* calls and callers tell it
 * apart by the code, never by the message.
 *
 * $options carries the gRPC call options, of which only `timeout` (microseconds) is honoured.
 *
 * @phpstan-type Metadata array<string, mixed>
 * @phpstan-type Options array<string, mixed>
 */
interface WorkflowServiceClientInterface
{
    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function CountActivityExecutions(Ws\CountActivityExecutionsRequest $request, array $metadata = [], array $options = []): Ws\CountActivityExecutionsResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function DeleteActivityExecution(Ws\DeleteActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\DeleteActivityExecutionResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function DescribeActivityExecution(Ws\DescribeActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\DescribeActivityExecutionResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function DescribeWorkflowExecution(Ws\DescribeWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\DescribeWorkflowExecutionResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function GetWorkflowExecutionHistory(Ws\GetWorkflowExecutionHistoryRequest $request, array $metadata = [], array $options = []): Ws\GetWorkflowExecutionHistoryResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function ListActivityExecutions(Ws\ListActivityExecutionsRequest $request, array $metadata = [], array $options = []): Ws\ListActivityExecutionsResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function ListWorkflowExecutions(Ws\ListWorkflowExecutionsRequest $request, array $metadata = [], array $options = []): Ws\ListWorkflowExecutionsResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function PauseActivity(Ws\PauseActivityRequest $request, array $metadata = [], array $options = []): Ws\PauseActivityResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function PollActivityExecution(Ws\PollActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\PollActivityExecutionResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function PollActivityTaskQueue(Ws\PollActivityTaskQueueRequest $request, array $metadata = [], array $options = []): Ws\PollActivityTaskQueueResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function PollNexusTaskQueue(Ws\PollNexusTaskQueueRequest $request, array $metadata = [], array $options = []): Ws\PollNexusTaskQueueResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function PollWorkflowExecutionUpdate(Ws\PollWorkflowExecutionUpdateRequest $request, array $metadata = [], array $options = []): Ws\PollWorkflowExecutionUpdateResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function PollWorkflowTaskQueue(Ws\PollWorkflowTaskQueueRequest $request, array $metadata = [], array $options = []): Ws\PollWorkflowTaskQueueResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function QueryWorkflow(Ws\QueryWorkflowRequest $request, array $metadata = [], array $options = []): Ws\QueryWorkflowResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function RecordActivityTaskHeartbeat(Ws\RecordActivityTaskHeartbeatRequest $request, array $metadata = [], array $options = []): Ws\RecordActivityTaskHeartbeatResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function RecordActivityTaskHeartbeatById(Ws\RecordActivityTaskHeartbeatByIdRequest $request, array $metadata = [], array $options = []): Ws\RecordActivityTaskHeartbeatByIdResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function RequestCancelActivityExecution(Ws\RequestCancelActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\RequestCancelActivityExecutionResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function RequestCancelWorkflowExecution(Ws\RequestCancelWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\RequestCancelWorkflowExecutionResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function ResetActivity(Ws\ResetActivityRequest $request, array $metadata = [], array $options = []): Ws\ResetActivityResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function RespondActivityTaskCanceled(Ws\RespondActivityTaskCanceledRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskCanceledResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function RespondActivityTaskCanceledById(Ws\RespondActivityTaskCanceledByIdRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskCanceledByIdResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function RespondActivityTaskCompleted(Ws\RespondActivityTaskCompletedRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskCompletedResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function RespondActivityTaskCompletedById(Ws\RespondActivityTaskCompletedByIdRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskCompletedByIdResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function RespondActivityTaskFailed(Ws\RespondActivityTaskFailedRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskFailedResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function RespondActivityTaskFailedById(Ws\RespondActivityTaskFailedByIdRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskFailedByIdResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function RespondNexusTaskCompleted(Ws\RespondNexusTaskCompletedRequest $request, array $metadata = [], array $options = []): Ws\RespondNexusTaskCompletedResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function RespondNexusTaskFailed(Ws\RespondNexusTaskFailedRequest $request, array $metadata = [], array $options = []): Ws\RespondNexusTaskFailedResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function RespondWorkflowTaskCompleted(Ws\RespondWorkflowTaskCompletedRequest $request, array $metadata = [], array $options = []): Ws\RespondWorkflowTaskCompletedResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function RespondWorkflowTaskFailed(Ws\RespondWorkflowTaskFailedRequest $request, array $metadata = [], array $options = []): Ws\RespondWorkflowTaskFailedResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function SignalWithStartWorkflowExecution(Ws\SignalWithStartWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\SignalWithStartWorkflowExecutionResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function SignalWorkflowExecution(Ws\SignalWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\SignalWorkflowExecutionResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function StartActivityExecution(Ws\StartActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\StartActivityExecutionResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function TerminateWorkflowExecution(Ws\TerminateWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\TerminateWorkflowExecutionResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function StartWorkflowExecution(Ws\StartWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\StartWorkflowExecutionResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function TerminateActivityExecution(Ws\TerminateActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\TerminateActivityExecutionResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function UnpauseActivity(Ws\UnpauseActivityRequest $request, array $metadata = [], array $options = []): Ws\UnpauseActivityResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function UpdateActivityOptions(Ws\UpdateActivityOptionsRequest $request, array $metadata = [], array $options = []): Ws\UpdateActivityOptionsResponse;

    /**
     * @param Metadata $metadata
     * @param Options  $options
     */
    public function UpdateWorkflowExecution(Ws\UpdateWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\UpdateWorkflowExecutionResponse;
}
