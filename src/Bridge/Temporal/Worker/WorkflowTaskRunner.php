<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\NullEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Worker\WorkflowFiberDriver;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
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

        $commandBuffer = new TemporalWorkflowCommandBuffer($this->connection, $executionId, $history);

        // Les updates arrivent à côté du journal, sur la tâche : ils sont remis à l'exécution
        // pour cette passe, et l'ordre du journal reprend la main dès qu'ils y sont acceptés.
        $inboundUpdates = UpdateProtocol::inboundFrom($poll);

        $context = new ExecutionContext(
            $executionId,
            $history,
            $commandBuffer,
            new TemporalChildWorkflowRunner(),
            null,
            array_map(static fn(InboundUpdate $update) => $update->pending, $inboundUpdates),
        );

        $handler = $this->registry->getHandler($workflowTypeName, $history->startInput());

        $environment = new WorkflowEnvironment(
            $context,
            $this->runtime,
            null,
            $this->workflowDefinitionLoader,
        );

        $lifecycle = new TemporalWorkflowLifecycle(
            $commandBuffer,
            $history->cancellationRequestedCause(),
            $history->cancellationAlreadyDelivered(),
        );

        (new WorkflowFiberDriver($lifecycle))->run($executionId, $context, $environment, $handler);

        $commands = $commandBuffer->flush();

        // Acceptation et réponse partent sur la tâche courante — et *avant* les commandes du
        // workflow : le serveur refuse toute séquence où CompleteWorkflowExecution n'est pas la
        // dernière commande, et un update traité débloque justement souvent la complétion.
        $reply = UpdateProtocol::reply($inboundUpdates);

        return new WorkflowTaskResult([...$reply['commands'], ...$commands], $context->queryHandlers(), $reply['messages']);
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
