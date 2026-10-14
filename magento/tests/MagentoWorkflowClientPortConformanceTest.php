<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\Durable\CronSchedule;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\SearchAttributes;
use Gplanchat\Durable\SystemClock;
use Gplanchat\Durable\TaskQueue;
use Gplanchat\Durable\Testing\ConformanceClientWorkflow;
use Gplanchat\Durable\Testing\ConformanceCompensatingClientWorkflow;
use Gplanchat\Durable\Testing\ConformanceOtherClientWorkflow;
use Gplanchat\Durable\Testing\ConformanceUpdatableClientWorkflow;
use Gplanchat\Durable\Testing\WorkflowClientPortConformanceTestCase;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\WorkflowIdReusePolicy;
use Gplanchat\Durable\WorkflowRegistry;
use Gplanchat\Durable\WorkflowStartOptions;
use Gplanchat\Durable\WorkflowTimeouts;
use Gplanchat\DurableModule\Runtime\DatabaseBackend;
use Gplanchat\DurableModule\Runtime\DatabaseWorker;
use Gplanchat\DurableModule\Schema\JournalSchema;
use Gplanchat\DurableModule\Store\MagentoWorkflowRunProjection;

/**
 * The shared client port cases against the database backend's own stores on the journal's
 * database (#971 to #974). The backend's worker is a separate process in production; here the
 * dispatcher hands the client a drain, so that every call finds the workflow run as far as it can
 * go, the way the in-memory suite's dispatcher does.
 */
final class MagentoWorkflowClientPortConformanceTest extends WorkflowClientPortConformanceTestCase
{
    private DatabaseBackend $backend;
    private MagentoWorkflowRunProjection $runs;
    private WorkflowClientPort $client;

    protected function setUp(): void
    {
        $adapter = JournalHarness::adapter();
        $registry = new WorkflowRegistry();
        foreach ([ConformanceClientWorkflow::class, ConformanceOtherClientWorkflow::class, ConformanceCompensatingClientWorkflow::class, ConformanceUpdatableClientWorkflow::class] as $workflow) {
            $registry->registerClass($workflow);
        }
        $this->backend = new DatabaseBackend($adapter, JournalHarness::deploymentConfig(), new SystemClock(), $registry, new RegistryActivityExecutor(), new NullActivityHeartbeatSender(), 0);
        $this->runs = new MagentoWorkflowRunProjection($adapter, new JournalSchema($adapter));
        $this->client = $this->backend->workflowClient($this->draining(new DatabaseWorker($this->backend)), Duration::milliseconds(200));
    }

    protected function clientUnderTest(): WorkflowClientPort
    {
        return $this->client;
    }

    protected function catalogUnderTest(): WorkflowRunCatalogInterface
    {
        return $this->backend->catalog;
    }

    protected function endRun(ExecutionId $executionId, WorkflowRunStatus $outcome): void
    {
        $this->runs->recordOutcome($executionId, $outcome);
        $this->backend->metadata->markCompleted($executionId);
    }

    protected function appendEnding(Event $ending): void
    {
        $this->backend->eventStore->append($ending);
    }

    protected function journalOf(ExecutionId $executionId): array
    {
        return iterator_to_array($this->backend->events->readStream($executionId), false);
    }

    protected function historyLengthOf(ExecutionId $executionId): int
    {
        return $this->backend->events->countEventsInStream($executionId);
    }

    public static function refusedStartOptions(): iterable
    {
        yield 'cron' => [new WorkflowStartOptions(cronSchedule: CronSchedule::from('@every 5m')), ['cronSchedule']];
        yield 'task queue' => [new WorkflowStartOptions(taskQueue: TaskQueue::named('other')), ['taskQueue']];
        yield 'timeouts' => [new WorkflowStartOptions(timeouts: new WorkflowTimeouts(execution: Duration::seconds(5))), ['timeouts']];
        yield 'id reuse policy' => [new WorkflowStartOptions(workflowIdReusePolicy: WorkflowIdReusePolicy::RejectDuplicate), ['workflowIdReusePolicy']];
        yield 'search attributes' => [new WorkflowStartOptions(searchAttributes: SearchAttributes::none()->keyword('k', 'v')), ['searchAttributes']];
        yield 'two at once' => [new WorkflowStartOptions(cronSchedule: CronSchedule::from('@every 5m'), taskQueue: TaskQueue::named('other')), ['cronSchedule', 'taskQueue']];
    }

    protected function startedInputOf(ExecutionId $executionId): ?array
    {
        return $this->backend->metadata->get($executionId)['payload'] ?? null;
    }

    protected function assertTerminationWasRecorded(ExecutionId $executionId, string $reason): void
    {
        $failures = array_values(array_filter(
            $this->journalOf($executionId),
            static fn(object $event): bool => $event instanceof WorkflowExecutionFailed,
        ));
        self::assertCount(1, $failures);
        self::assertSame(WorkflowExecutionFailed::KIND_TERMINATED, $failures[0]->kind());
        self::assertSame($reason, $failures[0]->failureMessage());

        $written = $this->historyLengthOf($executionId);
        $this->backend->resumes->dispatchResume($executionId);
        $this->drain(new DatabaseWorker($this->backend));
        self::assertSame($written, $this->historyLengthOf($executionId), 'A resume after the termination ran workflow code.');
    }

    /**
     * Sends through the backend's own dispatcher, then plays the worker until the queues are empty.
     */
    private function draining(DatabaseWorker $worker): WorkflowResumeDispatcher
    {
        $queued = $this->backend->resumes;
        $drain = $this->drain(...);

        return new class ($queued, $worker, $drain) implements WorkflowResumeDispatcher {
            public function __construct(
                private readonly WorkflowResumeDispatcher $queued,
                private readonly DatabaseWorker $worker,
                private readonly \Closure $drain,
            ) {}

            public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void
            {
                $this->queued->dispatchResume($executionId, $pendingUpdates);
                ($this->drain)($this->worker);
            }

            public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void
            {
                $this->queued->dispatchResumeAwaiting($executionId, $fact);
            }

            public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
            {
                $this->queued->dispatchNewWorkflowRun($executionId, $workflowType, $payload);
                ($this->drain)($this->worker);
            }
        };
    }

    private function drain(DatabaseWorker $worker): void
    {
        while ($worker->tick()) {
        }
    }
}
