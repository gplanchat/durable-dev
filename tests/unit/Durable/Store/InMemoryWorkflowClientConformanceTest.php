<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Attribute\AsUpdateMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\CronSchedule;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Exception\DurableUpdateFailedException;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\Exception\UpdateFailureReason;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowStartOptionsRefused;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\SearchAttributes;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowClient;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\ProjectingEventStore;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\TaskQueue;
use Gplanchat\Durable\Testing\ConformanceClientWorkflow;
use Gplanchat\Durable\Testing\ConformanceOtherClientWorkflow;
use Gplanchat\Durable\Testing\ConformanceUpdatableClientWorkflow;
use Gplanchat\Durable\Testing\WorkflowClientPortConformanceTestCase;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowEnvironment;
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

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->rows = new InMemoryWorkflowMetadataStore();
        $this->catalog = new InMemoryWorkflowRunCatalog($this->events);
        $metadata = new ProjectingWorkflowMetadataStore($this->rows, $this->catalog);
        $registry = new WorkflowRegistry();
        $registry->registerClass(ConformanceClientWorkflow::class);
        $registry->registerClass(ConformanceOtherClientWorkflow::class);
        $registry->registerClass(ConformanceUpdatableClientWorkflow::class);
        $registry->registerClass(SleepingUpdatableWorkflow::class);

        $dispatcher = new class implements WorkflowResumeDispatcher {
            public ?ResumeWorkflowHandler $handler = null;

            public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void
            {
                ($this->handler)(new ResumeWorkflowMessage($executionId->toString(), $pendingUpdates));
            }

            public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void {}

            public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
            {
                ($this->handler)(new ResumeWorkflowMessage($executionId->toString()));
            }
        };
        $dispatcher->handler = new ResumeWorkflowHandler(
            new ExecutionEngine($this->events, new ExecutionRuntime($this->events, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true)),
            $registry,
            $metadata,
            $dispatcher,
            $this->events,
            new InMemoryChildWorkflowParentLinkStore(),
            new class implements WorkflowTimerDispatcher {
                public function dispatchTimerFire(ExecutionId $executionId, int $delayMs = 0): void {}
            },
            new WorkflowDefinitionLoader(),
        );
        $this->client = new InMemoryWorkflowClient($metadata, $dispatcher, $this->catalog, $this->events);
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

    public function testAResultOnARunThatContinuedAsNewWithoutAnIdIsRefusedByName(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->client->start($id, ConformanceClientWorkflow::TYPE);
        $this->appendEnding(new WorkflowContinuedAsNew($id, ConformanceClientWorkflow::TYPE, []));

        $this->expectException(UnsupportedByBackendException::class);
        $this->client->result($id, Duration::seconds(1));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function operationsStillToBuild(): iterable
    {
        foreach (self::operations() as $name => $case) {
            if (!\in_array($case[0], ['signal', 'update', 'result'], true)) {
                yield $name => $case;
            }
        }
    }

    #[DataProvider('operationsStillToBuild')]
    public function testEveryOperationStillToBuildThrowsOnAKnownExecution(string $operation): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->client->start($id, ConformanceClientWorkflow::TYPE);
        if ('query' === $operation) {
            $this->endRun($id, WorkflowRunStatus::Completed);
        }

        $this->expectException(UnsupportedByBackendException::class);
        match ($operation) {
            'query' => $this->client->query($id, 'status'),
            'cancel' => $this->client->cancel($id),
            default => $this->client->terminate($id),
        };
    }

    /**
     * Until task 1.7 follows the chain, an operation on a run that continued as new is refused
     * rather than delivered to a journal nobody reads any more.
     */
    #[DataProvider('actingOperations')]
    public function testAnExecutionThatContinuedAsNewIsNotEnded(string $operation): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->client->start($id, ConformanceClientWorkflow::TYPE);
        $this->endRun($id, WorkflowRunStatus::ContinuedAsNew);

        try {
            match ($operation) {
                'signal' => $this->client->signal($id, 'pay'),
                'update' => $this->client->update($id, 'change'),
                'cancel' => $this->client->cancel($id),
                default => $this->client->terminate($id),
            };
            self::fail('A run that continued as new is refused.');
        } catch (UnsupportedByBackendException $e) {
            self::assertStringContainsString($operation . '()', $e->getMessage());
        }
    }

    /**
     * Known gap of this backend, which Temporal does not have: a pass applies a pending update
     * only inside a condition await, so an update sent while the workflow sleeps is applied by no
     * pass. Temporal runs update handlers at any point.
     */
    public function testAnUpdateSentWhileTheWorkflowSleepsIsNotHandled(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->client->start($id, SleepingUpdatableWorkflow::TYPE);

        try {
            $this->client->update($id, 'change');
            self::fail('A pass applies updates only inside a condition await.');
        } catch (DurableUpdateFailedException $e) {
            self::assertSame(UpdateFailureReason::NotHandled, $e->reason);
        }
    }

    /**
     * Temporal rejects such an update (#1078 tracks the bridge); here no pass applies it.
     */
    public function testAnUpdateTheWorkflowDoesNotDeclareFailsAtOnce(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->client->start($id, ConformanceUpdatableClientWorkflow::TYPE);

        try {
            $this->client->update($id, 'nobody-declares-this');
            self::fail('An update nothing handles must throw.');
        } catch (DurableUpdateFailedException $e) {
            self::assertSame('nobody-declares-this', $e->updateName());
            self::assertSame(UpdateFailureReason::NotHandled, $e->reason);
        }
    }
}

#[AsWorkflow(SleepingUpdatableWorkflow::TYPE)]
final class SleepingUpdatableWorkflow
{
    public const TYPE = 'test.sleeping.updatable';

    /**
     * @param array<string, mixed> $payload
     */
    #[AsUpdateMethod('change')]
    public function change(array $payload): int
    {
        return 1;
    }

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): string
    {
        $env->sleep(3600);

        return 'done';
    }
}
