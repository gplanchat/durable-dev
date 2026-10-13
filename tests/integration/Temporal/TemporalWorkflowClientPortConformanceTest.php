<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\Journal\JournalExecutionIdResolver;
use Gplanchat\Bridge\Temporal\Port\TemporalWorkflowClientPort;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\CronSchedule;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\TaskQueue as StartTaskQueue;
use Gplanchat\Durable\Testing\ConformanceClientWorkflow;
use Gplanchat\Durable\Testing\ConformanceOtherClientWorkflow;
use Gplanchat\Durable\Testing\WorkflowClientPortConformanceTestCase;
use Gplanchat\Durable\WorkflowStartOptions;
use Gplanchat\Durable\WorkflowTimeouts;
use PHPUnit\Framework\Attributes\DataProvider;
use Temporal\Api\Command\V1\CancelWorkflowExecutionCommandAttributes;
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Command\V1\CompleteWorkflowExecutionCommandAttributes;
use Temporal\Api\Command\V1\ContinueAsNewWorkflowExecutionCommandAttributes;
use Temporal\Api\Command\V1\FailWorkflowExecutionCommandAttributes;
use Temporal\Api\Common\V1\Memo;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionRequest;
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
        $this->connection = self::freshNamespaceConnection(false);
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

    /**
     * A journal event has no direct form on Temporal, and the cases that read one run the
     * workflow's code, which this suite does not (see {@see runsWorkflowCode()}).
     */
    protected function journalOf(ExecutionId $executionId): array
    {
        throw UnsupportedByBackendException::forMethod('Temporal', 'journalOf', 'read the history through the Temporal client');
    }

    protected function assertTerminationWasRecorded(ExecutionId $executionId, string $reason): void
    {
        $history = $this->history($executionId);
        $closing = end($history);
        self::assertNotFalse($closing, 'The server holds no history for the execution.');

        self::assertSame(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_TERMINATED, $closing->getEventType(), 'The history must end with the termination.');
        self::assertSame($reason, $closing->getWorkflowExecutionTerminatedEventAttributes()?->getReason());
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
            'commands' => [$this->closingCommand($outcome, $executionId)],
        ]));
    }

    private function closingCommand(WorkflowRunStatus $outcome, ExecutionId $executionId): Command
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
            // The server opens the successor under the same workflow id; it carries no memo over.
            WorkflowRunStatus::ContinuedAsNew => $command
                ->setCommandType(CommandType::COMMAND_TYPE_CONTINUE_AS_NEW_WORKFLOW_EXECUTION)
                ->setContinueAsNewWorkflowExecutionCommandAttributes(new ContinueAsNewWorkflowExecutionCommandAttributes([
                    'workflow_type' => new WorkflowType(['name' => ConformanceClientWorkflow::TYPE]),
                    'task_queue' => new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]),
                    'memo' => self::memoOf($executionId),
                ])),
            WorkflowRunStatus::Running => self::fail('the suite never ends a run as running'),
        };
    }

    private static function memoOf(ExecutionId $executionId): Memo
    {
        $memo = new Memo();
        $memo->getFields()[JournalExecutionIdResolver::MEMO_KEY_DURABLE_EXECUTION_ID] = JsonPlainPayload::encode($executionId->toString());

        return $memo;
    }

    /**
     * Only a completed run refuses a restart under the default reuse policy: the server restarts the
     * id of a failed or cancelled run, and a continued run leaves a successor running (see the two
     * tests below). The journal backends refuse all four (#1083).
     */
    public static function endedOutcomesThatRefuseARestart(): iterable
    {
        yield 'completed' => [WorkflowRunStatus::Completed];
    }

    /**
     * The Temporal side of #1083, read on a live server: under the default reuse policy
     * (AllowDuplicateFailedOnly) a start after a failed or cancelled run succeeds. It opens a new
     * run under the same workflow id, with the new input.
     */
    #[DataProvider('restartableOutcomes')]
    public function testTemporalRestartsAnIdWhoseRunFailedOrWasCancelled(WorkflowRunStatus $outcome): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE, ['tag' => 'first']);
        $this->endRun($id, $outcome);
        $firstRun = $this->runIdOf($id);

        $this->clientUnderTest()->start($id, ConformanceOtherClientWorkflow::TYPE, ['tag' => 'second']);

        self::assertNotSame($firstRun, $this->runIdOf($id), 'The restart opens a new run.');
        self::assertSame(['tag' => 'second'], $this->startedInputOf($id));
        self::assertSame(WorkflowRunStatus::Running, $this->catalogUnderTest()->findRun($id)?->status);
    }

    /** @return iterable<string, array{WorkflowRunStatus}> */
    public static function restartableOutcomes(): iterable
    {
        yield 'failed' => [WorkflowRunStatus::Failed];
        yield 'cancelled' => [WorkflowRunStatus::Cancelled];
    }

    /**
     * A run that continued as new leaves its successor running under the same workflow id, so a start
     * fails there as it does on a running id. The catalogue shows the successor, not the closed run.
     */
    public function testTemporalRefusesAStartWhileTheSuccessorOfAContinuedRunRuns(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE, ['tag' => 'first']);
        $this->endRun($id, WorkflowRunStatus::ContinuedAsNew);

        try {
            $this->clientUnderTest()->start($id, ConformanceOtherClientWorkflow::TYPE, ['tag' => 'second']);
            self::fail('A start while the successor runs must fail.');
        } catch (WorkflowExecutionAlreadyStarted $e) {
            self::assertSame('exec-1', $e->executionId);
        }
        self::assertSame(WorkflowRunStatus::Running, $this->catalogUnderTest()->findRun($id)?->status);
    }

    private function runIdOf(ExecutionId $executionId): string
    {
        $info = $this->grpc->DescribeWorkflowExecution(new DescribeWorkflowExecutionRequest([
            'namespace' => $this->connection->namespace->name(),
            'execution' => new WorkflowExecution(['workflow_id' => WorkflowClient::workflowIdOf($executionId->toString())]),
        ]))->getWorkflowExecutionInfo();

        return (string) $info?->getExecution()?->getRunId();
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
