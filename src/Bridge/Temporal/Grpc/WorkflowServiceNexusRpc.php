<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Grpc;

use Temporal\Api\Workflowservice\V1\PollNexusTaskQueueRequest;
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
 * The RPCs that concern the **Nexus tasks** served by this component.
 *
 * `StartWorkflowExecution` sits here with the other three, and this is not a catch-all: probe 3.1
 * showed that what settles a deferred operation is the task's `callback` attached to the workflow
 * that fulfils it, through `completion_callbacks` — a field that can only be set at start.
 * Starting that workflow is therefore part of the act of responding, not of another one.
 *
 * `RequestCancelWorkflowExecution` sits here for the same reason: probe §4 showed that the
 * cancellation task names the token handed back at start, and that this token is the workflow that
 * carries the operation. Cancelling the operation means cancelling that workflow.
 *
 * @see \Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker
 */
final readonly class WorkflowServiceNexusRpc
{
    public function __construct(
        private WorkflowServiceClient $client,
    ) {}

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function pollNexusTaskQueue(
        PollNexusTaskQueueRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): PollNexusTaskQueueResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::LONG_POLL_US], $callOptions);
        $r = GrpcUnary::wait($this->client->PollNexusTaskQueue($request, $metadata, $opts));
        \assert($r instanceof PollNexusTaskQueueResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function respondNexusTaskCompleted(
        RespondNexusTaskCompletedRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): RespondNexusTaskCompletedResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->RespondNexusTaskCompleted($request, $metadata, $opts));
        \assert($r instanceof RespondNexusTaskCompletedResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function respondNexusTaskFailed(
        RespondNexusTaskFailedRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): RespondNexusTaskFailedResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->RespondNexusTaskFailed($request, $metadata, $opts));
        \assert($r instanceof RespondNexusTaskFailedResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function startWorkflowExecution(
        StartWorkflowExecutionRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): StartWorkflowExecutionResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->StartWorkflowExecution($request, $metadata, $opts));
        \assert($r instanceof StartWorkflowExecutionResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function requestCancelWorkflowExecution(
        RequestCancelWorkflowExecutionRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): RequestCancelWorkflowExecutionResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->RequestCancelWorkflowExecution($request, $metadata, $opts));
        \assert($r instanceof RequestCancelWorkflowExecutionResponse);

        return $r;
    }
}
