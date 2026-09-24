<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Grpc;

use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Temporal\Api\Workflowservice\V1\CountActivityExecutionsRequest;
use Temporal\Api\Workflowservice\V1\CountActivityExecutionsResponse;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RecordActivityTaskHeartbeatRequest;
use Temporal\Api\Workflowservice\V1\RecordActivityTaskHeartbeatResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCanceledRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCanceledResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCompletedResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskFailedRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskFailedResponse;

/**
 * Typed wrappers for the {@see WorkflowServiceClientInterface} RPCs the activity worker uses: poll an
 * activity task, heartbeat it, and respond (completed, failed, canceled), plus the execution count the
 * tests read. The control-plane and standalone-execution RPCs had no caller and went (#372).
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
    public function countActivityExecutions(
        CountActivityExecutionsRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): CountActivityExecutionsResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = $this->client->CountActivityExecutions($request, $metadata, $opts);

        return $r;
    }

}
