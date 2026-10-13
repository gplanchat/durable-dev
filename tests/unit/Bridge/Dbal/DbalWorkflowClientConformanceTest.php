<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalChildWorkflowParentLinkStore;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowMetadataStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunProjection;
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
use unit\Bridge\SqlTestDatabase;
use unit\Durable\Store\FlakyResumeDispatcher;

/**
 * The client port on the DBAL journal, metadata and catalogue, with the dispatcher running each
 * resume in the caller's process. The client is the core one: it holds no state of its own and
 * works over the ports, so the DBAL stores are what this backend contributes.
 */
final class DbalWorkflowClientConformanceTest extends WorkflowClientPortConformanceTestCase
{
    private DbalEventStore $events;
    private DbalWorkflowMetadataStore $rows;
    private DbalWorkflowRunProjection $projection;
    private DbalWorkflowRunCatalog $catalog;
    private Connection $connection;
    private InMemoryWorkflowClient $client;
    private FlakyResumeDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->connection = $connection = SqlTestDatabase::dbal();
        $schema = new DurableSchema($connection);
        $this->events = new DbalEventStore($connection, $schema);
        $this->rows = new DbalWorkflowMetadataStore($connection, $schema);
        $this->projection = new DbalWorkflowRunProjection($connection, $schema);
        $this->catalog = new DbalWorkflowRunCatalog($connection, $schema);
        $metadata = new ProjectingWorkflowMetadataStore($this->rows, $this->projection);
        $journal = new ProjectingEventStore($this->events, $this->projection);
        $links = new DbalChildWorkflowParentLinkStore($connection, $schema);

        $registry = new WorkflowRegistry();
        $registry->registerClass(ConformanceClientWorkflow::class);
        $registry->registerClass(ConformanceOtherClientWorkflow::class);
        $registry->registerClass(ConformanceCompensatingClientWorkflow::class);
        $registry->registerClass(ConformanceUpdatableClientWorkflow::class);

        $this->dispatcher = $dispatcher = new FlakyResumeDispatcher();
        $engine = new ExecutionEngine(
            $journal,
            new ExecutionRuntime($journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true),
            workflowDefinitionLoader: new WorkflowDefinitionLoader(),
        );
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
        // A server database allows a bounded number of connections: one per test would exhaust it.
        $this->connection->close();
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
        $this->projection->recordOutcome($executionId, $outcome);
        $this->rows->markCompleted($executionId);
    }

    protected function appendEnding(Event $ending): void
    {
        (new ProjectingEventStore($this->events, $this->projection))->append($ending);
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
            static fn(object $event): bool => $event instanceof WorkflowExecutionFailed,
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
