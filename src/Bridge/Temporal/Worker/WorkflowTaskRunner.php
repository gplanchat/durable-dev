<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\NullEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;

/**
 * Replay-based workflow task runner for the Temporal backend.
 *
 * Algorithm (per DUR027):
 *  1. Stream history events from the poll response via TemporalHistoryCursor.
 *  2. Build TemporalExecutionHistory (indexed history for O(1) slot lookups).
 *  3. Create ExecutionContext with TemporalExecutionHistory (read) + TemporalWorkflowCommandBuffer (write).
 *  4. Start the workflow handler in a \Fiber.
 *  5. Drive the fiber: resume immediately for settled awaitables (replay), stop for unsettled ones (new commands).
 *  6. Return the collected commands to the caller (WorkflowTaskProcessor → RespondWorkflowTaskCompleted).
 *
 * The fiber is non-persistent: each workflow task starts a fresh fiber that replays the full history.
 * No pcntl_fork(), no Swoole, no RoadRunner — standard PHP-CLI only.
 */
final class WorkflowTaskRunner
{
    private readonly ExecutionRuntime $runtime;

    public function __construct(
        private readonly TemporalHistoryCursor $historyCursor,
        private readonly WorkflowRegistry $registry,
        private readonly TemporalConnection $connection,
        private readonly ?WorkflowDefinitionLoader $workflowDefinitionLoader = null,
    ) {
        $this->runtime = new ExecutionRuntime(
            new NullEventStore(),
            new NoopActivityTransport(),
            new RegistryActivityExecutor(),
            0,
            null,
            true,
        );
    }

    /**
     * Runs the workflow handler for the given poll response and returns the commands to send back.
     *
     * @return WorkflowTaskResult
     *
     * @throws \InvalidArgumentException if no handler is found for the workflow type
     * @throws \RuntimeException         on fiber or protocol errors
     */
    public function run(PollWorkflowTaskQueueResponse $poll): WorkflowTaskResult
    {
        $token = $poll->getTaskToken();
        if ('' === $token) {
            return new WorkflowTaskResult([], null);
        }

        $events = $this->historyCursor->eventsFromPoll($poll);
        $history = TemporalExecutionHistory::fromEvents($events);

        $executionId = $this->resolveExecutionId($poll, $history);

        $workflowTypeName = $this->resolveWorkflowTypeName($poll);

        $commandBuffer = new TemporalWorkflowCommandBuffer($this->connection, $executionId);

        $context = new ExecutionContext(
            $executionId,
            $history,
            $commandBuffer,
        );

        $handler = $this->registry->getHandler($workflowTypeName, $history->startInput());

        $environment = new WorkflowEnvironment(
            $context,
            $this->runtime,
            null,
            $this->workflowDefinitionLoader,
        );

        $fiber = new \Fiber(static fn () => $handler($environment));

        $this->driveFiber($fiber, $commandBuffer);

        $commands = $commandBuffer->flush();

        return new WorkflowTaskResult($commands, $environment);
    }

    /**
     * Drives the fiber until it terminates or produces a new (unsettled) command.
     *
     * On replay: awaitables are already settled → resume immediately.
     * On new command: awaitable is unsettled → stop; the command is in the buffer.
     */
    private function driveFiber(
        \Fiber $fiber,
        TemporalWorkflowCommandBuffer $commandBuffer,
    ): void {
        try {
            $suspended = $fiber->start();
        } catch (\Throwable $e) {
            $commandBuffer->failWorkflow($e);

            return;
        }

        while ($fiber->isSuspended()) {
            if (!($suspended instanceof Awaitable)) {
                break;
            }

            if ($suspended->isSettled()) {
                try {
                    $suspended = $fiber->resume();
                } catch (\Throwable $e) {
                    $commandBuffer->failWorkflow($e);

                    return;
                }
            } else {
                // Unsettled awaitable: the command was buffered, stop the fiber for this task.
                return;
            }
        }

        if ($fiber->isTerminated()) {
            $result = $fiber->getReturn();
            $commandBuffer->completeWorkflow($result);
        }
    }

    private function resolveExecutionId(
        PollWorkflowTaskQueueResponse $poll,
        TemporalExecutionHistory $history,
    ): string {
        $fromMemo = $history->durableExecutionId();
        if (null !== $fromMemo && '' !== $fromMemo) {
            return $fromMemo;
        }

        $exec = $poll->getWorkflowExecution();
        if (null !== $exec) {
            $wfId = $exec->getWorkflowId();
            if ('' !== $wfId) {
                return $wfId;
            }
        }

        throw new \RuntimeException('Cannot resolve durable execution ID from workflow task (no memo, no workflowId).');
    }

    private function resolveWorkflowTypeName(PollWorkflowTaskQueueResponse $poll): string
    {
        $wfType = $poll->getWorkflowType();
        if (null !== $wfType) {
            $name = $wfType->getName();
            if ('' !== $name) {
                return $name;
            }
        }

        return $this->connection->workflowType;
    }
}
