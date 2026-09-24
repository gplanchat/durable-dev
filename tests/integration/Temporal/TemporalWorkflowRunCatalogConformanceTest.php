<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Testing\WorkflowRunCatalogConformanceTestCase;
use Temporal\Api\Command\V1\CancelWorkflowExecutionCommandAttributes;
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Command\V1\CompleteWorkflowExecutionCommandAttributes;
use Temporal\Api\Command\V1\ContinueAsNewWorkflowExecutionCommandAttributes;
use Temporal\Api\Command\V1\FailWorkflowExecutionCommandAttributes;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\DeleteWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\DeleteWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\RequestCancelWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;

/**
 * DUR041's catalog suite against a real Temporal server. The hooks play the worker by hand: each
 * run gets a task queue of its own, and ending it answers its first workflow task with the command
 * that closes it that way. A catalog reads the visibility store, which lags behind the history, so
 * every hook waits until the listing shows what it just did.
 *
 * @see DUR041
 * @see DUR037
 */
final class TemporalWorkflowRunCatalogConformanceTest extends WorkflowRunCatalogConformanceTestCase
{
    use FreshNamespace;

    private const VISIBILITY_TIMEOUT_SECONDS = 15.0;

    private TemporalConnection $connection;
    private WorkflowServiceClientInterface $client;

    protected function setUp(): void
    {
        $this->connection = self::freshNamespaceConnection();
        $this->client = WorkflowServiceClientFactory::create($this->connection);
    }

    protected function catalogUnderTest(): WorkflowRunCatalogInterface
    {
        return new TemporalWorkflowRunCatalog($this->client, $this->connection, new TemporalHistoryCursor($this->client, $this->connection));
    }

    protected function executionIdOf(WorkflowRunDescription $run): string
    {
        return $run->groupId ?? '';
    }

    protected function startRun(string $executionId, string $workflowType): void
    {
        $this->client->StartWorkflowExecution(new StartWorkflowExecutionRequest([
            'namespace' => $this->namespace(),
            'workflow_id' => $executionId,
            'workflow_type' => new WorkflowType(['name' => $workflowType]),
            'task_queue' => new TaskQueue(['name' => self::queueOf($executionId)]),
            'request_id' => bin2hex(random_bytes(16)),
        ]));

        $this->awaitListed($executionId, WorkflowRunStatus::Running);
    }

    protected function endRun(string $executionId, WorkflowRunStatus $outcome): void
    {
        if (WorkflowRunStatus::Cancelled === $outcome) {
            $this->client->RequestCancelWorkflowExecution(new RequestCancelWorkflowExecutionRequest([
                'namespace' => $this->namespace(),
                'workflow_execution' => new WorkflowExecution(['workflow_id' => $executionId]),
            ]));
        }

        $task = $this->client->PollWorkflowTaskQueue(new PollWorkflowTaskQueueRequest([
            'namespace' => $this->namespace(),
            'task_queue' => new TaskQueue(['name' => self::queueOf($executionId)]),
            'identity' => $this->connection->identity,
        ]), [], ['timeout' => 10_000_000]);
        self::assertNotSame('', $task->getTaskToken(), \sprintf('no workflow task came for "%s"', $executionId));

        $this->client->RespondWorkflowTaskCompleted(new RespondWorkflowTaskCompletedRequest([
            'namespace' => $this->namespace(),
            'task_token' => $task->getTaskToken(),
            'identity' => $this->connection->identity,
            'commands' => [self::closingCommand($outcome, $executionId)],
        ]));

        if (WorkflowRunStatus::ContinuedAsNew === $outcome) {
            // The successor run is Temporal's own fact, not the run the suite ended: out of sight.
            WorkflowServiceClientFactory::createTransport($this->connection)->unary(
                '/temporal.api.workflowservice.v1.WorkflowService/DeleteWorkflowExecution',
                new DeleteWorkflowExecutionRequest([
                    'namespace' => $this->namespace(),
                    'workflow_execution' => new WorkflowExecution(['workflow_id' => $executionId]),
                ]),
                DeleteWorkflowExecutionResponse::class,
                [],
                10_000,
            );
        }

        $this->awaitListed($executionId, $outcome);
    }

    private static function closingCommand(WorkflowRunStatus $outcome, string $executionId): Command
    {
        $command = new Command();
        match ($outcome) {
            WorkflowRunStatus::Completed => $command
                ->setCommandType(CommandType::COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION)
                ->setCompleteWorkflowExecutionCommandAttributes(new CompleteWorkflowExecutionCommandAttributes()),
            WorkflowRunStatus::Failed => $command
                ->setCommandType(CommandType::COMMAND_TYPE_FAIL_WORKFLOW_EXECUTION)
                ->setFailWorkflowExecutionCommandAttributes(new FailWorkflowExecutionCommandAttributes([
                    'failure' => new Failure(['message' => 'conformance']),
                ])),
            WorkflowRunStatus::Cancelled => $command
                ->setCommandType(CommandType::COMMAND_TYPE_CANCEL_WORKFLOW_EXECUTION)
                ->setCancelWorkflowExecutionCommandAttributes(new CancelWorkflowExecutionCommandAttributes()),
            WorkflowRunStatus::ContinuedAsNew => $command
                ->setCommandType(CommandType::COMMAND_TYPE_CONTINUE_AS_NEW_WORKFLOW_EXECUTION)
                ->setContinueAsNewWorkflowExecutionCommandAttributes(new ContinueAsNewWorkflowExecutionCommandAttributes([
                    'workflow_type' => new WorkflowType(['name' => 'conformance-successor']),
                    'task_queue' => new TaskQueue(['name' => self::queueOf($executionId)]),
                ])),
            WorkflowRunStatus::Running => self::fail('a run cannot be ended as running'),
        };

        return $command;
    }

    /**
     * Waits until the listing shows this execution with exactly this status, and no other run of it.
     */
    private function awaitListed(string $executionId, WorkflowRunStatus $status): void
    {
        $deadline = microtime(true) + self::VISIBILITY_TIMEOUT_SECONDS;
        do {
            $seen = [];
            foreach ($this->catalogUnderTest()->listRuns(null, null, 100)->runs as $run) {
                if ($executionId === $run->groupId) {
                    $seen[] = $run->status;
                }
            }
            if ([$status] === $seen) {
                return;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        self::fail(\sprintf(
            'The listing still shows "%s" as [%s] %.0f s later, expected [%s].',
            $executionId,
            implode(', ', array_map(static fn(WorkflowRunStatus $s): string => $s->name, $seen)),
            self::VISIBILITY_TIMEOUT_SECONDS,
            $status->name,
        ));
    }

    private function namespace(): string
    {
        return $this->connection->namespace->name();
    }

    private static function queueOf(string $executionId): string
    {
        return 'conformance-' . $executionId;
    }
}
