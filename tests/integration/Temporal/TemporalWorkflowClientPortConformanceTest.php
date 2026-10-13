<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\Port\TemporalWorkflowClientPort;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\CronSchedule;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\TaskQueue as StartTaskQueue;
use Gplanchat\Durable\Testing\ConformanceClientWorkflow;
use Gplanchat\Durable\Testing\WorkflowClientPortConformanceTestCase;
use Gplanchat\Durable\WorkflowStartOptions;
use Gplanchat\Durable\WorkflowTimeouts;
use Temporal\Api\Command\V1\CancelWorkflowExecutionCommandAttributes;
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Command\V1\CompleteWorkflowExecutionCommandAttributes;
use Temporal\Api\Command\V1\FailWorkflowExecutionCommandAttributes;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\RequestCancelWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;

/**
 * The Temporal port against a real server, on the suite every backend passes. A fresh namespace
 * and task queue per test; the hooks play the worker by hand, as the catalog suite does: ending a
 * run answers its first workflow task with the command that closes it that way.
 *
 * Needs DURABLE_TEMPORAL_ADDRESS; the CI job that runs the `temporal` suite provides a dev server.
 */
final class TemporalWorkflowClientPortConformanceTest extends WorkflowClientPortConformanceTestCase
{
    use FreshNamespace;

    private TemporalConnection $connection;
    private WorkflowServiceClientInterface $grpc;
    private WorkflowClient $workflowClient;

    protected function setUp(): void
    {
        $this->connection = self::freshNamespaceConnection();
        $this->grpc = WorkflowServiceClientFactory::create($this->connection);
        $cursor = new TemporalHistoryCursor($this->grpc, $this->connection);
        $this->workflowClient = new WorkflowClient($this->grpc, $this->connection, $cursor, new WorkflowServiceExecutionRpc($this->grpc));
    }

    protected function clientUnderTest(): WorkflowClientPort
    {
        return new TemporalWorkflowClientPort($this->workflowClient);
    }

    protected function catalogUnderTest(): WorkflowRunCatalogInterface
    {
        return new TemporalWorkflowRunCatalog($this->grpc, $this->connection, new TemporalHistoryCursor($this->grpc, $this->connection));
    }

    /** The server honours every start option: none is refused. */
    public static function refusedStartOptions(): iterable
    {
        return [];
    }

    /**
     * Overrides the shared refusal case, which PHPUnit cannot run on an empty provider: Temporal has
     * no option to refuse, so the case checked here is the opposite one. A start that sets the cron
     * schedule, the task queue and the execution timeout succeeds, and the server records all three.
     */
    public function testAnOptionTheBackendCannotHonourFailsTheStartAndStartsNothing(?WorkflowStartOptions $options = null, array $names = []): void
    {
        $id = ExecutionId::fromString('exec-1');
        $options = new WorkflowStartOptions(
            cronSchedule: CronSchedule::from('@every 5m'),
            taskQueue: StartTaskQueue::named('other-queue'),
            timeouts: new WorkflowTimeouts(execution: Duration::seconds(3600)),
        );

        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE, [], $options);

        $started = $this->history($id)[0]->getWorkflowExecutionStartedEventAttributes();
        self::assertNotNull($started);
        self::assertSame('@every 5m', $started->getCronSchedule());
        self::assertSame('other-queue', $started->getTaskQueue()?->getName());
        self::assertSame(3600, $started->getWorkflowExecutionTimeout()?->getSeconds());
    }

    protected function startedInputOf(ExecutionId $executionId): ?array
    {
        $first = $this->history($executionId)[0] ?? null;
        $payloads = $first?->getWorkflowExecutionStartedEventAttributes()?->getInput()?->getPayloads();
        if (null === $payloads) {
            return null;
        }

        return 0 === $payloads->count() ? [] : JsonPlainPayload::decode($payloads[0]);
    }

    protected function runsWorkflowCode(): bool
    {
        return false;
    }

    protected function historyLengthOf(ExecutionId $executionId): int
    {
        return \count($this->history($executionId));
    }

    protected function endRun(ExecutionId $executionId, WorkflowRunStatus $outcome): void
    {
        if (WorkflowRunStatus::Cancelled === $outcome) {
            $this->grpc->RequestCancelWorkflowExecution(new RequestCancelWorkflowExecutionRequest([
                'namespace' => $this->connection->namespace->name(),
                'workflow_execution' => new WorkflowExecution(['workflow_id' => WorkflowClient::workflowIdOf($executionId->toString())]),
            ]));
        }
        $task = $this->grpc->PollWorkflowTaskQueue(new PollWorkflowTaskQueueRequest([
            'namespace' => $this->connection->namespace->name(),
            'task_queue' => new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]),
            'identity' => $this->connection->identity,
        ]), [], ['timeout' => 10_000_000]);
        self::assertNotSame('', $task->getTaskToken(), 'no workflow task came for the execution');

        $this->grpc->RespondWorkflowTaskCompleted(new RespondWorkflowTaskCompletedRequest([
            'namespace' => $this->connection->namespace->name(),
            'task_token' => $task->getTaskToken(),
            'identity' => $this->connection->identity,
            'commands' => [self::closingCommand($outcome)],
        ]));
    }

    private static function closingCommand(WorkflowRunStatus $outcome): Command
    {
        $command = new Command();

        return match ($outcome) {
            WorkflowRunStatus::Completed => $command
                ->setCommandType(CommandType::COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION)
                ->setCompleteWorkflowExecutionCommandAttributes(new CompleteWorkflowExecutionCommandAttributes()),
            WorkflowRunStatus::Failed => $command
                ->setCommandType(CommandType::COMMAND_TYPE_FAIL_WORKFLOW_EXECUTION)
                ->setFailWorkflowExecutionCommandAttributes(new FailWorkflowExecutionCommandAttributes(['failure' => new Failure(['message' => 'conformance'])])),
            WorkflowRunStatus::Cancelled => $command
                ->setCommandType(CommandType::COMMAND_TYPE_CANCEL_WORKFLOW_EXECUTION)
                ->setCancelWorkflowExecutionCommandAttributes(new CancelWorkflowExecutionCommandAttributes()),
            default => self::fail('the suite never ends a run as ' . $outcome->value),
        };
    }

    /** @return list<\Temporal\Api\History\V1\HistoryEvent> empty when the server holds no such workflow */
    private function history(ExecutionId $executionId): array
    {
        $cursor = new TemporalHistoryCursor($this->grpc, $this->connection);
        $execution = new WorkflowExecution(['workflow_id' => WorkflowClient::workflowIdOf($executionId->toString())]);

        try {
            return iterator_to_array($cursor->events($execution), false);
        } catch (\RuntimeException $failure) {
            if (5 === $failure->getCode()) {
                return [];
            }

            throw $failure;
        }
    }
}
