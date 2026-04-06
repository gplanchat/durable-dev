<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Grpc;

/**
 * Default gRPC call deadlines (microseconds) for Temporal {@see \Temporal\Api\Workflowservice\V1\WorkflowServiceClient}.
 * Long polls require an explicit deadline; unary calls use a shorter default.
 */
final class TemporalGrpcTimeouts
{
    public const LONG_POLL_US = 120_000_000;

    public const SHORT_US = 60_000_000;

    public const HISTORY_US = 60_000_000;

    public const RESPOND_WORKFLOW_TASK_US = 60_000_000;

    private function __construct()
    {
    }
}
