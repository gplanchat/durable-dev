<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

/**
 * Contract for driving Temporal workflow executions from application code.
 *
 * Abstracting {@see WorkflowClient} (final) behind this interface allows consumers —
 * including symfony/ sample application code and unit tests — to substitute a test
 * double without subclassing the concrete gRPC-bound class.
 *
 * @see WorkflowClient concrete Temporal gRPC implementation
 */
interface WorkflowClientInterface
{
    /**
     * Starts a workflow asynchronously (fire and forget).
     *
     * @param array<string, mixed> $payload Business payload for the workflow input.
     * @return string The Temporal workflow ID used.
     */
    public function startAsync(string $workflowType, array $payload, string $executionId): string;

    /**
     * Starts a workflow and blocks until WorkflowExecutionCompleted.
     *
     * @param array<string, mixed> $payload Business payload for the workflow input.
     * @return mixed The decoded result of the workflow.
     */
    public function startSync(string $workflowType, array $payload, string $executionId): mixed;

    /**
     * Polls Temporal for workflow completion, retrying periodically until the workflow terminates.
     *
     * @param int $refreshIntervalMs Milliseconds between poll attempts (default: 500 ms).
     * @param int $maxRefreshes      Maximum number of attempts before throwing (default: 120 = 60 s total).
     *
     * @throws \RuntimeException when the workflow fails, is cancelled, or times out on the Temporal side.
     * @throws \RuntimeException when no completion event is found within {@code $maxRefreshes} attempts.
     */
    public function pollForCompletion(
        string $executionId,
        int $refreshIntervalMs = 500,
        int $maxRefreshes = 120,
    ): mixed;

    /**
     * Delivers an external signal to a running workflow.
     *
     * @param array<string, mixed> $args Signal arguments.
     */
    public function signal(string $workflowId, string $signalName, array $args = []): void;

    /**
     * Evaluates a query on a running workflow.
     *
     * @param array<string, mixed> $args Query arguments.
     * @return mixed The decoded query result.
     */
    public function query(string $workflowId, string $queryType, array $args = []): mixed;

    /**
     * Delivers a transactional update to a running workflow and waits for the result.
     *
     * @param array<string, mixed> $args Update arguments.
     * @return mixed The decoded update result.
     */
    public function update(string $workflowId, string $updateName, array $args = []): mixed;

    /**
     * Computes the Temporal workflow ID for a given Durable execution ID.
     */
    public function workflowId(string $executionId): string;
}
