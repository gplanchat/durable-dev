<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Gplanchat\Durable\Exception\WorkflowStuckException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\WorkflowStartOptions;

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
     * Returns the execution it was given, not the Temporal workflow id it used: that one is
     * {@see workflowId()}, and it is what signal(), query() and update() take (#638).
     *
     * @param array<string, mixed> $payload Business payload for the workflow input.
     * @param WorkflowStartOptions|null $options Timeouts, id reuse policy, memo, cron, search attributes.
     * @return ExecutionId The execution started.
     */
    public function startAsync(string $workflowType, array $payload, ExecutionId $executionId, ?WorkflowStartOptions $options = null): ExecutionId;

    /**
     * Starts a workflow and blocks until WorkflowExecutionCompleted.
     *
     * @param array<string, mixed> $payload Business payload for the workflow input.
     * @param WorkflowStartOptions|null $options Timeouts, id reuse policy, memo, cron, search attributes.
     * @return mixed The decoded result of the workflow.
     */
    public function startSync(string $workflowType, array $payload, ExecutionId $executionId, ?WorkflowStartOptions $options = null): mixed;

    /**
     * Polls Temporal for workflow completion, retrying periodically until the workflow terminates.
     *
     * @param int $refreshIntervalMs Milliseconds between poll attempts (default: 500 ms).
     * @param int $maxRefreshes      Maximum number of attempts before throwing (default: 120 = 60 s total).
     *
     * @throws \Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException when the workflow
     *         let an activity failure escape, as on the journal backends; the original failure is its previous.
     * @throws \RuntimeException when the workflow fails, is cancelled, or times out on the Temporal side.
     * @throws WorkflowStuckException when no completion event is found within {@code $maxRefreshes} attempts.
     *                                 It extends \RuntimeException.
     */
    public function pollForCompletion(
        string $executionId,
        int $refreshIntervalMs = 500,
        int $maxRefreshes = 120,
    ): mixed;

    /**
     * Delivers an external signal to a running workflow.
     *
     * The name is given as a {@see \BackedEnum}, as on the workflow side; the bare string stays
     * accepted for emitters that are not PHP (ADR DUR034).
     *
     * @param array<string, mixed> $args      Signal arguments.
     * @param string|null          $requestId One id per logical signal: the cluster drops a second
     *                                        one carrying it. Drawn by the client when null.
     */
    public function signal(string $workflowId, \BackedEnum|string $signalName, array $args = [], ?string $requestId = null): void;

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
     * @param array<string, mixed> $args     Update arguments.
     * @param string|null          $updateId One id per logical update: the cluster answers a second
     *                                       one carrying it with the first outcome. Drawn by the
     *                                       client when null.
     * @return mixed The decoded update result.
     */
    public function update(string $workflowId, string $updateName, array $args = [], ?string $updateId = null): mixed;

    /**
     * Computes the Temporal workflow ID for a given Durable execution ID.
     */
    public function workflowId(ExecutionId $executionId): string;
}
