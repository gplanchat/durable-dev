<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\CronSchedule;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\SupersededPassException;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyEnded;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowStartOptionsRefused;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\LinkedChildFinalizer;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\ParentChildWorkflowCoordinator;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\SearchAttributes;
use Gplanchat\Durable\Store\FencedEventStoreInterface;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowClient;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\PassFence;
use Gplanchat\Durable\Store\ProjectingEventStore;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\TaskQueue;
use Gplanchat\Durable\Testing\ConformanceClientWorkflow;
use Gplanchat\Durable\Testing\ConformanceCompensatingClientWorkflow;
use Gplanchat\Durable\Testing\ConformanceOtherClientWorkflow;
use Gplanchat\Durable\Testing\WorkflowClientPortConformanceTestCase;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowIdReusePolicy;
use Gplanchat\Durable\WorkflowRegistry;
use Gplanchat\Durable\WorkflowStartOptions;
use Gplanchat\Durable\WorkflowTimeouts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The in-memory client on a stack that runs the first pass of a workflow in the caller's process,
 * as the memory backend does (#603).
 */
final class InMemoryWorkflowClientConformanceTest extends WorkflowClientPortConformanceTestCase
{
    private InMemoryEventStore $events;
    private InMemoryWorkflowMetadataStore $rows;
    private InMemoryWorkflowRunCatalog $catalog;
    private InMemoryWorkflowClient $client;
    private WorkflowResumeDispatcher $dispatcher;
    private InMemoryChildWorkflowParentLinkStore $links;
    private ProjectingEventStore $journal;
    private ProjectingWorkflowMetadataStore $metadata;
    private \unit\Durable\Store\FlakyResumeDispatcher $flaky;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->rows = new InMemoryWorkflowMetadataStore();
        $this->catalog = new InMemoryWorkflowRunCatalog($this->events);
        $metadata = new ProjectingWorkflowMetadataStore($this->rows, $this->catalog);
        // As the memory backend wires it: the endings an append carries reach the catalogue.
        $this->journal = $journal = new ProjectingEventStore($this->events, $this->catalog);
        $this->metadata = $metadata;
        $registry = new WorkflowRegistry();
        $registry->registerClass(ConformanceClientWorkflow::class);
        $registry->registerClass(ConformanceOtherClientWorkflow::class);
        $registry->registerClass(ConformanceCompensatingClientWorkflow::class);

        $this->flaky = $this->dispatcher = $dispatcher = new \unit\Durable\Store\FlakyResumeDispatcher();
        $dispatcher->handler = new ResumeWorkflowHandler(
            new ExecutionEngine($journal, new ExecutionRuntime($journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true)),
            $registry,
            $metadata,
            $dispatcher,
            $journal,
            $this->links = new InMemoryChildWorkflowParentLinkStore(),
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
            new LinkedChildFinalizer($journal, $dispatcher, $this->links),
            new ParentChildWorkflowCoordinator($journal, $dispatcher),
        );
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
    }

    protected function historyLengthOf(ExecutionId $executionId): int
    {
        return $this->events->countEventsInStream($executionId);
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
        return $this->rows->get($executionId)['payload'] ?? null;
    }

    protected function assertTerminationWasRecorded(ExecutionId $executionId, string $reason): void
    {
        $failures = array_values(array_filter(
            iterator_to_array($this->events->readStream($executionId), false),
            static fn(object $event): bool => $event instanceof WorkflowExecutionFailed,
        ));
        self::assertCount(1, $failures);
        self::assertSame(WorkflowExecutionFailed::KIND_TERMINATED, $failures[0]->kind());
        self::assertSame($reason, $failures[0]->failureMessage());

        $written = $this->events->countEventsInStream($executionId);
        $this->resumeAgain($executionId);
        self::assertSame($written, $this->events->countEventsInStream($executionId), 'A resume after the termination ran workflow code.');
    }

    private function resumeAgain(ExecutionId $executionId): void
    {
        $this->dispatcher->dispatchResume($executionId);
    }

    public function testAStartRunsTheWorkflowsFirstPassAndASecondStartRunsNothing(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->client->start($id, ConformanceClientWorkflow::TYPE);
        $written = $this->events->countEventsInStream($id);

        self::assertGreaterThan(0, $written, 'The start only wrote a row: nothing ran the workflow.');

        try {
            $this->client->start($id, ConformanceClientWorkflow::TYPE);
            self::fail('A second start must fail.');
        } catch (WorkflowExecutionAlreadyStarted) {
            self::assertSame($written, $this->events->countEventsInStream($id));
        }
    }

    public function testARefusedStartNamesTheBackendAndWritesNoRowBeforeTheCheck(): void
    {
        $id = ExecutionId::fromString('exec-1');

        try {
            $this->client->start($id, ConformanceClientWorkflow::TYPE, [], new WorkflowStartOptions(taskQueue: TaskQueue::named('other')));
            self::fail('A refused option must fail the start.');
        } catch (WorkflowStartOptionsRefused $e) {
            self::assertSame('in-memory', $e->backend);
        }

        self::assertNull($this->rows->get($id));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function operationsStillToBuild(): iterable
    {
        foreach (['signal', 'query', 'update', 'result'] as $operation) {
            yield $operation => [$operation];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function signalAndUpdate(): iterable
    {
        yield 'signal' => ['signal'];
        yield 'update' => ['update'];
    }

    #[DataProvider('operationsStillToBuild')]
    public function testEveryOperationStillToBuildThrowsOnAKnownExecution(string $operation): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->client->start($id, ConformanceClientWorkflow::TYPE);
        if (\in_array($operation, ['query', 'result'], true)) {
            $this->endRun($id, WorkflowRunStatus::Completed);
        }

        $this->expectException(UnsupportedByBackendException::class);
        match ($operation) {
            'signal' => $this->client->signal($id, 'pay'),
            'query' => $this->client->query($id, 'status'),
            'update' => $this->client->update($id, 'change'),
            default => $this->client->result($id, Duration::seconds(0.1)),
        };
    }

    #[DataProvider('actingOperations')]
    public function testAnExecutionThatContinuedAsNewIsNotEnded(string $operation): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->client->start($id, ConformanceClientWorkflow::TYPE);
        $this->endRun($id, WorkflowRunStatus::ContinuedAsNew);

        $this->expectException(UnsupportedByBackendException::class);
        match ($operation) {
            'signal' => $this->client->signal($id, 'pay'),
            'update' => $this->client->update($id, 'change'),
            'cancel' => $this->client->cancel($id),
            default => $this->client->terminate($id),
        };
    }

    #[DataProvider('actingOperations')]
    public function testAnExecutionThatContinuedAsNewLeavesTheJournalUntouched(string $operation): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->client->start($id, ConformanceClientWorkflow::TYPE);
        $this->endRun($id, WorkflowRunStatus::ContinuedAsNew);
        $written = $this->events->countEventsInStream($id);

        $refused = null;

        try {
            match ($operation) {
                'signal' => $this->client->signal($id, 'pay'),
                'update' => $this->client->update($id, 'change'),
                'cancel' => $this->client->cancel($id),
                default => $this->client->terminate($id),
            };
        } catch (\Throwable $e) {
            $refused = $e;
        }

        self::assertInstanceOf(UnsupportedByBackendException::class, $refused);
        self::assertSame($written, $this->events->countEventsInStream($id));
        self::assertSame(WorkflowRunStatus::ContinuedAsNew, $this->catalog->findRun($id)?->status);
    }

    public function testTerminatingALinkedChildTellsItsParent(): void
    {
        $parent = ExecutionId::fromString('parent-1');
        $child = ExecutionId::fromString('child-1');
        $this->client->start($parent, ConformanceClientWorkflow::TYPE);
        $this->client->start($child, ConformanceClientWorkflow::TYPE);
        $this->events->append(new ChildWorkflowScheduled($parent, $child, ConformanceClientWorkflow::TYPE, []));
        $this->links->link($child, $parent);

        $this->client->terminate($child, 'stop');

        $failed = array_values(array_filter(
            iterator_to_array($this->events->readStream($parent), false),
            static fn(object $event): bool => $event instanceof ChildWorkflowFailed,
        ));
        self::assertCount(1, $failed, 'The parent waits for its child forever.');
        // What Temporal gives the parent of a terminated child: the text, no kind, no class.
        self::assertSame('Child workflow child-1 was terminated.', $failed[0]->payload()['failureMessage']);
        self::assertNull($failed[0]->workflowFailureKind());
        self::assertNull($failed[0]->payload()['workflowFailureClass'] ?? null);
        self::assertNull($this->links->getParentExecutionId($child));
    }

    public function testTerminatingAParentClosesItsChildUnderTheTerminatePolicy(): void
    {
        $parent = ExecutionId::fromString('parent-1');
        $child = ExecutionId::fromString('child-1');
        $this->client->start($parent, ConformanceClientWorkflow::TYPE);
        $this->client->start($child, ConformanceClientWorkflow::TYPE);
        // A child started by its parent has its start journalled; a run started through the client has none.
        $this->events->append(new ExecutionStarted($child, ['workflowType' => ConformanceClientWorkflow::TYPE]));
        $this->events->append(new ChildWorkflowScheduled($parent, $child, ConformanceClientWorkflow::TYPE, [], ParentClosePolicy::Terminate));

        $this->client->terminate($parent);

        self::assertSame(WorkflowRunStatus::Failed, $this->catalog->findRun($child)?->status);
    }

    public function testATerminationSupersedesThePassThatWasRunning(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->client->start($id, ConformanceClientWorkflow::TYPE);
        $running = $this->events->claimPass($id);

        $this->client->terminate($id);

        $this->expectException(SupersededPassException::class);
        $this->events->appendFenced(new ExecutionCompleted($id, 'late'), $running);
    }

    public function testARetriedTerminationFinishesWhatAFailedDispatchLeftUndone(): void
    {
        $parent = ExecutionId::fromString('parent-1');
        $child = ExecutionId::fromString('child-1');
        $this->client->start($parent, ConformanceClientWorkflow::TYPE);
        $this->client->start($child, ConformanceClientWorkflow::TYPE);
        $this->events->append(new ChildWorkflowScheduled($parent, $child, ConformanceClientWorkflow::TYPE, []));
        $this->links->link($child, $parent);
        $this->flaky->failOnce = new class ('the queue is down') extends \RuntimeException {};

        try {
            $this->client->terminate($child, 'stop');
            self::fail('The dispatch failure must reach the caller.');
        } catch (\RuntimeException $e) {
            self::assertSame('the queue is down', $e->getMessage());
        }
        self::assertNotTrue($this->rows->get($child)['completed'] ?? false, 'The row is marked completed last.');

        $this->client->terminate($child, 'stop');

        self::assertCount(1, array_filter(
            iterator_to_array($this->events->readStream($parent), false),
            static fn(object $event): bool => $event instanceof ChildWorkflowFailed,
        ));
        self::assertTrue($this->rows->get($child)['completed'] ?? false);
        $this->expectException(WorkflowExecutionAlreadyEnded::class);
        $this->client->terminate($child, 'stop');
    }

    public function testARunThatCompletesBetweenTheCheckAndTheClaimIsNotTerminated(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->client->start($id, ConformanceClientWorkflow::TYPE);
        $racing = new class ($this->journal) implements FencedEventStoreInterface {
            public function __construct(private readonly ProjectingEventStore $inner) {}

            public function append(Event $event): void
            {
                $this->inner->append($event);
            }

            public function readStream(ExecutionId $executionId): iterable
            {
                return $this->inner->readStream($executionId);
            }

            public function readStreamWithRecordedAt(ExecutionId $executionId): iterable
            {
                return $this->inner->readStreamWithRecordedAt($executionId);
            }

            public function countEventsInStream(ExecutionId $executionId): int
            {
                return $this->inner->countEventsInStream($executionId);
            }

            public function claimPass(ExecutionId $executionId): PassFence
            {
                // The pass that was running ends before the claim.
                $this->inner->append(new ExecutionCompleted($executionId, 'done'));

                return $this->inner->claimPass($executionId);
            }

            public function appendFenced(Event $event, PassFence $fence): void
            {
                $this->inner->appendFenced($event, $fence);
            }
        };
        $client = new InMemoryWorkflowClient($this->metadata, $this->dispatcher, $this->catalog, $racing, new LinkedChildFinalizer($racing, $this->dispatcher, $this->links));

        try {
            $client->terminate($id);
            self::fail('A run that completed cannot be terminated.');
        } catch (WorkflowExecutionAlreadyEnded) {
            self::assertSame(WorkflowRunStatus::Completed, $this->catalog->findRun($id)?->status);
        }
        self::assertSame([], array_filter(
            iterator_to_array($this->events->readStream($id), false),
            static fn(object $event): bool => $event instanceof WorkflowExecutionFailed,
        ));
    }
}
