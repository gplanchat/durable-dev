<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Grpc;

use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Temporal\Api\Workflowservice\V1\CountActivityExecutionsRequest;
use Temporal\Api\Workflowservice\V1\CountActivityExecutionsResponse;
use Temporal\Api\Workflowservice\V1\DeleteActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\DeleteActivityExecutionResponse;
use Temporal\Api\Workflowservice\V1\DescribeActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\DescribeActivityExecutionResponse;
use Temporal\Api\Workflowservice\V1\ListActivityExecutionsRequest;
use Temporal\Api\Workflowservice\V1\ListActivityExecutionsResponse;
use Temporal\Api\Workflowservice\V1\PollActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\PollActivityExecutionResponse;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RecordActivityTaskHeartbeatRequest;
use Temporal\Api\Workflowservice\V1\RecordActivityTaskHeartbeatResponse;
use Temporal\Api\Workflowservice\V1\RequestCancelActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\RequestCancelActivityExecutionResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCanceledRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCanceledResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCompletedResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskFailedRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskFailedResponse;
use Temporal\Api\Workflowservice\V1\StartActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\StartActivityExecutionResponse;
use Temporal\Api\Workflowservice\V1\TerminateActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\TerminateActivityExecutionResponse;

/**
 * Typed wrappers for Temporal {@see WorkflowServiceClientInterface} RPCs that concern **activity tasks**
 * and **activity execution** (poll, respond, heartbeat, cancel, visibility, control-plane).
 *
 * Each method applies a default gRPC deadline; pass {@code $callOptions} (e.g. {@code ['timeout' => …]}) to override.
 *
 * @see \Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker
 */
final readonly class WorkflowServiceActivityRpc
{
    public function __construct(
        private WorkflowServiceClientInterface $client,
    ) {}

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function pollActivityTaskQueue(
        PollActivityTaskQueueRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): PollActivityTaskQueueResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::LONG_POLL_US], $callOptions);
        $r = $this->client->PollActivityTaskQueue($request, $metadata, $opts);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function recordActivityTaskHeartbeat(
        RecordActivityTaskHeartbeatRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): RecordActivityTaskHeartbeatResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = $this->client->RecordActivityTaskHeartbeat($request, $metadata, $opts);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function respondActivityTaskCompleted(
        RespondActivityTaskCompletedRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): RespondActivityTaskCompletedResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = $this->client->RespondActivityTaskCompleted($request, $metadata, $opts);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function respondActivityTaskFailed(
        RespondActivityTaskFailedRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): RespondActivityTaskFailedResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = $this->client->RespondActivityTaskFailed($request, $metadata, $opts);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function respondActivityTaskCanceled(
        RespondActivityTaskCanceledRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): RespondActivityTaskCanceledResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = $this->client->RespondActivityTaskCanceled($request, $metadata, $opts);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function startActivityExecution(
        StartActivityExecutionRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): StartActivityExecutionResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = $this->client->StartActivityExecution($request, $metadata, $opts);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function describeActivityExecution(
        DescribeActivityExecutionRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): DescribeActivityExecutionResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = $this->client->DescribeActivityExecution($request, $metadata, $opts);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function pollActivityExecution(
        PollActivityExecutionRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): PollActivityExecutionResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::LONG_POLL_US], $callOptions);
        $r = $this->client->PollActivityExecution($request, $metadata, $opts);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function listActivityExecutions(
        ListActivityExecutionsRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): ListActivityExecutionsResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = $this->client->ListActivityExecutions($request, $metadata, $opts);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function countActivityExecutions(
        CountActivityExecutionsRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): CountActivityExecutionsResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = $this->client->CountActivityExecutions($request, $metadata, $opts);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function requestCancelActivityExecution(
        RequestCancelActivityExecutionRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): RequestCancelActivityExecutionResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = $this->client->RequestCancelActivityExecution($request, $metadata, $opts);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function terminateActivityExecution(
        TerminateActivityExecutionRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): TerminateActivityExecutionResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = $this->client->TerminateActivityExecution($request, $metadata, $opts);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function deleteActivityExecution(
        DeleteActivityExecutionRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): DeleteActivityExecutionResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = $this->client->DeleteActivityExecution($request, $metadata, $opts);

        return $r;
    }
}
