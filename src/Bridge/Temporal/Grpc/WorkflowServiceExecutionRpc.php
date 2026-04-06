<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Grpc;

use Temporal\Api\Workflowservice\V1\PollWorkflowExecutionUpdateRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowExecutionUpdateResponse;
use Temporal\Api\Workflowservice\V1\QueryWorkflowRequest;
use Temporal\Api\Workflowservice\V1\QueryWorkflowResponse;
use Temporal\Api\Workflowservice\V1\UpdateWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\UpdateWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Typed wrappers for **client → running workflow** `WorkflowService` RPCs (query, update, poll update outcome).
 *
 * Distinct from {@see WorkflowServiceActivityRpc} (activity worker poll / respond / heartbeat).
 * Default deadlines follow {@see TemporalGrpcTimeouts}; override via {@code $callOptions}.
 */
final readonly class WorkflowServiceExecutionRpc
{
    public function __construct(
        private WorkflowServiceClient $client,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function queryWorkflow(
        QueryWorkflowRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): QueryWorkflowResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->QueryWorkflow($request, $metadata, $opts));
        \assert($r instanceof QueryWorkflowResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function updateWorkflowExecution(
        UpdateWorkflowExecutionRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): UpdateWorkflowExecutionResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->UpdateWorkflowExecution($request, $metadata, $opts));
        \assert($r instanceof UpdateWorkflowExecutionResponse);

        return $r;
    }

    /**
     * Long-poll for the outcome of an update started via {@see updateWorkflowExecution}.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function pollWorkflowExecutionUpdate(
        PollWorkflowExecutionUpdateRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): PollWorkflowExecutionUpdateResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::LONG_POLL_US], $callOptions);
        $r = GrpcUnary::wait($this->client->PollWorkflowExecutionUpdate($request, $metadata, $opts));
        \assert($r instanceof PollWorkflowExecutionUpdateResponse);

        return $r;
    }
}
