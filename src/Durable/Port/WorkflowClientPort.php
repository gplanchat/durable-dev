<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyEnded;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowResultTimeout;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\WorkflowStartOptions;

/**
 * What an application does to an execution, keyed by {@see ExecutionId} and identical on every
 * backend (openspec change `workflow-repository`). The workflow stub and the repository sit on
 * top of it; they check the workflow type through the run catalogue, so the port takes none.
 *
 * Every operation except {@see start()} throws {@see WorkflowExecutionNotFound} for an id no
 * execution holds.
 */
interface WorkflowClientPort
{
    /**
     * Starts an execution and returns without waiting for its result.
     *
     * A start option the backend cannot honour makes the start fail, naming every refused option
     * and the backend; no execution starts.
     *
     * @param array<string, mixed> $input
     *
     * @throws WorkflowExecutionAlreadyStarted the first execution keeps its type, input and progress
     */
    public function start(ExecutionId $executionId, string $workflowType, array $input = [], ?WorkflowStartOptions $options = null): void;

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws WorkflowExecutionNotFound
     */
    public function signal(ExecutionId $executionId, string $name, array $arguments = []): void;

    /**
     * Adds no event to the execution's history.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws WorkflowExecutionNotFound
     */
    public function query(ExecutionId $executionId, string $name, array $arguments = []): mixed;

    /**
     * Returns the result of this update, even when another caller sent the same name and arguments.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws WorkflowExecutionNotFound
     */
    public function update(ExecutionId $executionId, string $name, array $arguments = []): mixed;

    /**
     * Waits for the result; a failed execution throws its failure.
     *
     * @param Duration|null $timeout null waits without a bound
     *
     * @throws WorkflowExecutionNotFound
     * @throws WorkflowResultTimeout
     */
    public function result(ExecutionId $executionId, ?Duration $timeout = null): mixed;

    /**
     * Requests cancellation; the workflow receives it where it waits.
     *
     * @throws WorkflowExecutionNotFound
     * @throws WorkflowExecutionAlreadyEnded
     */
    public function cancel(ExecutionId $executionId): void;

    /**
     * Ends the execution without running more of its code.
     *
     * @throws WorkflowExecutionNotFound
     * @throws WorkflowExecutionAlreadyEnded
     */
    public function terminate(ExecutionId $executionId, string $reason = ''): void;
}
