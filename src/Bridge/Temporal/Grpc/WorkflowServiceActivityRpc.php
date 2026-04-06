<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Grpc;

use Temporal\Api\Workflowservice\V1\CountActivityExecutionsRequest;
use Temporal\Api\Workflowservice\V1\CountActivityExecutionsResponse;
use Temporal\Api\Workflowservice\V1\DeleteActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\DeleteActivityExecutionResponse;
use Temporal\Api\Workflowservice\V1\DescribeActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\DescribeActivityExecutionResponse;
use Temporal\Api\Workflowservice\V1\ListActivityExecutionsRequest;
use Temporal\Api\Workflowservice\V1\ListActivityExecutionsResponse;
use Temporal\Api\Workflowservice\V1\PauseActivityRequest;
use Temporal\Api\Workflowservice\V1\PauseActivityResponse;
use Temporal\Api\Workflowservice\V1\PollActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\PollActivityExecutionResponse;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RecordActivityTaskHeartbeatByIdRequest;
use Temporal\Api\Workflowservice\V1\RecordActivityTaskHeartbeatByIdResponse;
use Temporal\Api\Workflowservice\V1\RecordActivityTaskHeartbeatRequest;
use Temporal\Api\Workflowservice\V1\RecordActivityTaskHeartbeatResponse;
use Temporal\Api\Workflowservice\V1\RequestCancelActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\RequestCancelActivityExecutionResponse;
use Temporal\Api\Workflowservice\V1\ResetActivityRequest;
use Temporal\Api\Workflowservice\V1\ResetActivityResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCanceledByIdRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCanceledByIdResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCanceledRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCanceledResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCompletedByIdRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCompletedByIdResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCompletedResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskFailedByIdRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskFailedByIdResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskFailedRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskFailedResponse;
use Temporal\Api\Workflowservice\V1\StartActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\StartActivityExecutionResponse;
use Temporal\Api\Workflowservice\V1\TerminateActivityExecutionRequest;
use Temporal\Api\Workflowservice\V1\TerminateActivityExecutionResponse;
use Temporal\Api\Workflowservice\V1\UnpauseActivityRequest;
use Temporal\Api\Workflowservice\V1\UnpauseActivityResponse;
use Temporal\Api\Workflowservice\V1\UpdateActivityOptionsRequest;
use Temporal\Api\Workflowservice\V1\UpdateActivityOptionsResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Typed wrappers for Temporal {@see WorkflowServiceClient} RPCs that concern **activity tasks**
 * and **activity execution** (poll, respond, heartbeat, cancel, visibility, control-plane).
 *
 * Each method applies a default gRPC deadline; pass {@code $callOptions} (e.g. {@code ['timeout' => …]}) to override.
 *
 * @see \Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker
 */
final readonly class WorkflowServiceActivityRpc
{
    public function __construct(
        private WorkflowServiceClient $client,
    ) {
    }

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
        $r = GrpcUnary::wait($this->client->PollActivityTaskQueue($request, $metadata, $opts));
        \assert($r instanceof PollActivityTaskQueueResponse);

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
        $r = GrpcUnary::wait($this->client->RecordActivityTaskHeartbeat($request, $metadata, $opts));
        \assert($r instanceof RecordActivityTaskHeartbeatResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function recordActivityTaskHeartbeatById(
        RecordActivityTaskHeartbeatByIdRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): RecordActivityTaskHeartbeatByIdResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->RecordActivityTaskHeartbeatById($request, $metadata, $opts));
        \assert($r instanceof RecordActivityTaskHeartbeatByIdResponse);

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
        $r = GrpcUnary::wait($this->client->RespondActivityTaskCompleted($request, $metadata, $opts));
        \assert($r instanceof RespondActivityTaskCompletedResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function respondActivityTaskCompletedById(
        RespondActivityTaskCompletedByIdRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): RespondActivityTaskCompletedByIdResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->RespondActivityTaskCompletedById($request, $metadata, $opts));
        \assert($r instanceof RespondActivityTaskCompletedByIdResponse);

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
        $r = GrpcUnary::wait($this->client->RespondActivityTaskFailed($request, $metadata, $opts));
        \assert($r instanceof RespondActivityTaskFailedResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function respondActivityTaskFailedById(
        RespondActivityTaskFailedByIdRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): RespondActivityTaskFailedByIdResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->RespondActivityTaskFailedById($request, $metadata, $opts));
        \assert($r instanceof RespondActivityTaskFailedByIdResponse);

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
        $r = GrpcUnary::wait($this->client->RespondActivityTaskCanceled($request, $metadata, $opts));
        \assert($r instanceof RespondActivityTaskCanceledResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function respondActivityTaskCanceledById(
        RespondActivityTaskCanceledByIdRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): RespondActivityTaskCanceledByIdResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->RespondActivityTaskCanceledById($request, $metadata, $opts));
        \assert($r instanceof RespondActivityTaskCanceledByIdResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function updateActivityOptions(
        UpdateActivityOptionsRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): UpdateActivityOptionsResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->UpdateActivityOptions($request, $metadata, $opts));
        \assert($r instanceof UpdateActivityOptionsResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function pauseActivity(
        PauseActivityRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): PauseActivityResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->PauseActivity($request, $metadata, $opts));
        \assert($r instanceof PauseActivityResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function unpauseActivity(
        UnpauseActivityRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): UnpauseActivityResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->UnpauseActivity($request, $metadata, $opts));
        \assert($r instanceof UnpauseActivityResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function resetActivity(
        ResetActivityRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): ResetActivityResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->ResetActivity($request, $metadata, $opts));
        \assert($r instanceof ResetActivityResponse);

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
        $r = GrpcUnary::wait($this->client->StartActivityExecution($request, $metadata, $opts));
        \assert($r instanceof StartActivityExecutionResponse);

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
        $r = GrpcUnary::wait($this->client->DescribeActivityExecution($request, $metadata, $opts));
        \assert($r instanceof DescribeActivityExecutionResponse);

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
        $r = GrpcUnary::wait($this->client->PollActivityExecution($request, $metadata, $opts));
        \assert($r instanceof PollActivityExecutionResponse);

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
        $r = GrpcUnary::wait($this->client->ListActivityExecutions($request, $metadata, $opts));
        \assert($r instanceof ListActivityExecutionsResponse);

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
        $r = GrpcUnary::wait($this->client->CountActivityExecutions($request, $metadata, $opts));
        \assert($r instanceof CountActivityExecutionsResponse);

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
        $r = GrpcUnary::wait($this->client->RequestCancelActivityExecution($request, $metadata, $opts));
        \assert($r instanceof RequestCancelActivityExecutionResponse);

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
        $r = GrpcUnary::wait($this->client->TerminateActivityExecution($request, $metadata, $opts));
        \assert($r instanceof TerminateActivityExecutionResponse);

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
        $r = GrpcUnary::wait($this->client->DeleteActivityExecution($request, $metadata, $opts));
        \assert($r instanceof DeleteActivityExecutionResponse);

        return $r;
    }
}
