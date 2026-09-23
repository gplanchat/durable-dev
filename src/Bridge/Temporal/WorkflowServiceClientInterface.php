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
    public function CountActivityExecutions(Ws\CountActivityExecutionsRequest $request, array $metadata = [], array $options = []): Ws\CountActivityExecutionsResponse;

    public function DeleteActivityExecution(Ws\DeleteActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\DeleteActivityExecutionResponse;

    public function DescribeActivityExecution(Ws\DescribeActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\DescribeActivityExecutionResponse;

    public function DescribeWorkflowExecution(Ws\DescribeWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\DescribeWorkflowExecutionResponse;

    public function GetWorkflowExecutionHistory(Ws\GetWorkflowExecutionHistoryRequest $request, array $metadata = [], array $options = []): Ws\GetWorkflowExecutionHistoryResponse;

    public function ListActivityExecutions(Ws\ListActivityExecutionsRequest $request, array $metadata = [], array $options = []): Ws\ListActivityExecutionsResponse;

    public function ListWorkflowExecutions(Ws\ListWorkflowExecutionsRequest $request, array $metadata = [], array $options = []): Ws\ListWorkflowExecutionsResponse;

    public function PauseActivity(Ws\PauseActivityRequest $request, array $metadata = [], array $options = []): Ws\PauseActivityResponse;

    public function PollActivityExecution(Ws\PollActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\PollActivityExecutionResponse;

    public function PollActivityTaskQueue(Ws\PollActivityTaskQueueRequest $request, array $metadata = [], array $options = []): Ws\PollActivityTaskQueueResponse;

    public function PollNexusTaskQueue(Ws\PollNexusTaskQueueRequest $request, array $metadata = [], array $options = []): Ws\PollNexusTaskQueueResponse;

    public function PollWorkflowExecutionUpdate(Ws\PollWorkflowExecutionUpdateRequest $request, array $metadata = [], array $options = []): Ws\PollWorkflowExecutionUpdateResponse;

    public function PollWorkflowTaskQueue(Ws\PollWorkflowTaskQueueRequest $request, array $metadata = [], array $options = []): Ws\PollWorkflowTaskQueueResponse;

    public function QueryWorkflow(Ws\QueryWorkflowRequest $request, array $metadata = [], array $options = []): Ws\QueryWorkflowResponse;

    public function RecordActivityTaskHeartbeat(Ws\RecordActivityTaskHeartbeatRequest $request, array $metadata = [], array $options = []): Ws\RecordActivityTaskHeartbeatResponse;

    public function RecordActivityTaskHeartbeatById(Ws\RecordActivityTaskHeartbeatByIdRequest $request, array $metadata = [], array $options = []): Ws\RecordActivityTaskHeartbeatByIdResponse;

    public function RequestCancelActivityExecution(Ws\RequestCancelActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\RequestCancelActivityExecutionResponse;

    public function RequestCancelWorkflowExecution(Ws\RequestCancelWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\RequestCancelWorkflowExecutionResponse;

    public function ResetActivity(Ws\ResetActivityRequest $request, array $metadata = [], array $options = []): Ws\ResetActivityResponse;

    public function RespondActivityTaskCanceled(Ws\RespondActivityTaskCanceledRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskCanceledResponse;

    public function RespondActivityTaskCanceledById(Ws\RespondActivityTaskCanceledByIdRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskCanceledByIdResponse;

    public function RespondActivityTaskCompleted(Ws\RespondActivityTaskCompletedRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskCompletedResponse;

    public function RespondActivityTaskCompletedById(Ws\RespondActivityTaskCompletedByIdRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskCompletedByIdResponse;

    public function RespondActivityTaskFailed(Ws\RespondActivityTaskFailedRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskFailedResponse;

    public function RespondActivityTaskFailedById(Ws\RespondActivityTaskFailedByIdRequest $request, array $metadata = [], array $options = []): Ws\RespondActivityTaskFailedByIdResponse;

    public function RespondNexusTaskCompleted(Ws\RespondNexusTaskCompletedRequest $request, array $metadata = [], array $options = []): Ws\RespondNexusTaskCompletedResponse;

    public function RespondNexusTaskFailed(Ws\RespondNexusTaskFailedRequest $request, array $metadata = [], array $options = []): Ws\RespondNexusTaskFailedResponse;

    public function RespondWorkflowTaskCompleted(Ws\RespondWorkflowTaskCompletedRequest $request, array $metadata = [], array $options = []): Ws\RespondWorkflowTaskCompletedResponse;

    public function RespondWorkflowTaskFailed(Ws\RespondWorkflowTaskFailedRequest $request, array $metadata = [], array $options = []): Ws\RespondWorkflowTaskFailedResponse;

    public function SignalWithStartWorkflowExecution(Ws\SignalWithStartWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\SignalWithStartWorkflowExecutionResponse;

    public function SignalWorkflowExecution(Ws\SignalWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\SignalWorkflowExecutionResponse;

    public function StartActivityExecution(Ws\StartActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\StartActivityExecutionResponse;

    public function TerminateWorkflowExecution(Ws\TerminateWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\TerminateWorkflowExecutionResponse;

    public function StartWorkflowExecution(Ws\StartWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\StartWorkflowExecutionResponse;

    public function TerminateActivityExecution(Ws\TerminateActivityExecutionRequest $request, array $metadata = [], array $options = []): Ws\TerminateActivityExecutionResponse;

    public function UnpauseActivity(Ws\UnpauseActivityRequest $request, array $metadata = [], array $options = []): Ws\UnpauseActivityResponse;

    public function UpdateActivityOptions(Ws\UpdateActivityOptionsRequest $request, array $metadata = [], array $options = []): Ws\UpdateActivityOptionsResponse;

    public function UpdateWorkflowExecution(Ws\UpdateWorkflowExecutionRequest $request, array $metadata = [], array $options = []): Ws\UpdateWorkflowExecutionResponse;
}
