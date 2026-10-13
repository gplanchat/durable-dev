<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateChildWorkflowParentLinkStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateEventStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowMetadataStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowRunCatalog;
use Gplanchat\Durable\CronSchedule;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\LinkedChildFinalizer;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\ParentChildWorkflowCoordinator;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Query\ReadOnlyReplay;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\SearchAttributes;
use Gplanchat\Durable\Store\InMemoryWorkflowClient;
use Gplanchat\Durable\Store\ProjectingEventStore;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\TaskQueue;
use Gplanchat\Durable\Testing\ConformanceClientWorkflow;
use Gplanchat\Durable\Testing\ConformanceCompensatingClientWorkflow;
use Gplanchat\Durable\Testing\ConformanceOtherClientWorkflow;
use Gplanchat\Durable\Testing\ConformanceUpdatableClientWorkflow;
use Gplanchat\Durable\Testing\WorkflowClientPortConformanceTestCase;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowIdReusePolicy;
use Gplanchat\Durable\WorkflowRegistry;
use Gplanchat\Durable\WorkflowStartOptions;
use Gplanchat\Durable\WorkflowTimeouts;
use Illuminate\Database\Connection;
use unit\Bridge\SqlTestDatabase;
use unit\Durable\Store\FlakyResumeDispatcher;

/**
 * The journal client of the core on the Illuminate stores: the shared port suite, on a real
 * database. The client takes the store interfaces, so it is the same class as on the memory
 * backend; only the stores differ.
 */
final class IlluminateWorkflowClientConformanceTest extends WorkflowClientPortConformanceTestCase
{
    private IlluminateEventStore $events;
    private IlluminateWorkflowMetadataStore $rows;
    private IlluminateWorkflowRunCatalog $catalog;
    private Connection $connection;
    private InMemoryWorkflowClient $client;
    private FlakyResumeDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->connection = $connection = SqlTestDatabase::illuminate();
        $schema = new DurableSchema($connection);
        $this->events = new IlluminateEventStore($connection, $schema);
        $this->rows = new IlluminateWorkflowMetadataStore($connection, $schema);
        $this->catalog = new IlluminateWorkflowRunCatalog($connection, $schema);
        $metadata = new ProjectingWorkflowMetadataStore($this->rows, $this->catalog);
        $journal = new ProjectingEventStore($this->events, $this->catalog);
        $registry = new WorkflowRegistry();
        foreach ([ConformanceClientWorkflow::class, ConformanceOtherClientWorkflow::class, ConformanceCompensatingClientWorkflow::class, ConformanceUpdatableClientWorkflow::class] as $class) {
            $registry->registerClass($class);
        }

        $this->dispatcher = $dispatcher = new FlakyResumeDispatcher();
        $engine = new ExecutionEngine(
            $journal,
            new ExecutionRuntime($journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true),
            workflowDefinitionLoader: new WorkflowDefinitionLoader(),
        );
        $links = new IlluminateChildWorkflowParentLinkStore($connection, $schema);
        $dispatcher->handler = new ResumeWorkflowHandler(
            $engine,
            $registry,
            $metadata,
            $dispatcher,
            $journal,
            $links,
            new class implements WorkflowTimerDispatcher {
                public function dispatchTimerFire(ExecutionId $executionId, int $delayMs = 0): void {}
            },
            new WorkflowDefinitionLoader(),
        );
        $this->client = new InMemoryWorkflowClient(
            $metadata,
            $dispatcher,
            $this->catalog,
            $journal,
            new ReadOnlyReplay($engine, $journal, $metadata, $registry),
            new LinkedChildFinalizer($journal, $dispatcher, $links),
            new ParentChildWorkflowCoordinator($journal, $dispatcher),
        );
    }

    protected function tearDown(): void
    {
        // A server database keeps one session per test otherwise: PostgreSQL runs out of clients.
        $this->connection->disconnect();
    }

    protected function clientUnderTest(): WorkflowClientPort
    {
        return $this->client;
    }

    protected function catalogUnderTest(): WorkflowRunCatalogInterface
    {
        return $this->catalog;
    }

    protected function endRun(ExecutionId $executionId, WorkflowRunStatus $outcome): void
    {
        $this->catalog->recordOutcome($executionId, $outcome);
        $this->rows->markCompleted($executionId);
    }

    protected function appendEnding(Event $ending): void
    {
        (new ProjectingEventStore($this->events, $this->catalog))->append($ending);
    }

    protected function journalOf(ExecutionId $executionId): array
    {
        return iterator_to_array($this->events->readStream($executionId), false);
    }

    protected function historyLengthOf(ExecutionId $executionId): int
    {
        return $this->events->countEventsInStream($executionId);
    }

    protected function startedInputOf(ExecutionId $executionId): ?array
    {
        return $this->rows->get($executionId)['payload'] ?? null;
    }

    protected function assertTerminationWasRecorded(ExecutionId $executionId, string $reason): void
    {
        $failures = array_values(array_filter(
            $this->journalOf($executionId),
            static fn(Event $event): bool => $event instanceof WorkflowExecutionFailed,
        ));
        self::assertCount(1, $failures);
        self::assertSame(WorkflowExecutionFailed::KIND_TERMINATED, $failures[0]->kind());
        self::assertSame($reason, $failures[0]->failureMessage());

        $written = $this->historyLengthOf($executionId);
        $this->dispatcher->dispatchResume($executionId);
        self::assertSame($written, $this->historyLengthOf($executionId), 'A resume after the termination ran workflow code.');
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
}
