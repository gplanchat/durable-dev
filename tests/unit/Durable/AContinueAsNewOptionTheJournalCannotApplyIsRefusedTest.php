<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\ContinueAsNewOptions;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreWorkflowLifecycle;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\TaskQueue;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use Gplanchat\Durable\WorkflowTimeouts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The journal backends (InMemory, Doctrine DBAL, Illuminate, Magento) record a continue-as-new but have no task queue
 * to move to and no timer for the run bounds. They refuse those options by name (#977); Temporal
 * applies them (see TemporalWorkflowFailureRoundTripTest).
 */
final class AContinueAsNewOptionTheJournalCannotApplyIsRefusedTest extends TestCase
{
    /** @return iterable<string, array{ContinueAsNewOptions, string}> */
    public static function unsupportedOptions(): iterable
    {
        yield 'task queue' => [new ContinueAsNewOptions(taskQueue: TaskQueue::named('next-queue')), 'ContinueAsNewOptions::$taskQueue'];
        yield 'run timeout' => [new ContinueAsNewOptions(timeouts: new WorkflowTimeouts(run: Duration::minutes(5))), 'ContinueAsNewOptions::$timeouts->run'];
        yield 'task timeout' => [new ContinueAsNewOptions(timeouts: new WorkflowTimeouts(task: Duration::seconds(30))), 'ContinueAsNewOptions::$timeouts->task'];
    }

    #[DataProvider('unsupportedOptions')]
    public function testTheOptionIsRefusedAndOnlyTheFailureIsJournaled(ContinueAsNewOptions $options, string $name): void
    {
        $store = new InMemoryEventStore();
        $id = ExecutionId::generate();

        try {
            (new EventStoreWorkflowLifecycle($store))->onContinuedAsNew($id, new ContinueAsNewRequested('Next', [], $options));
            self::fail('The journal lifecycle must refuse the option.');
        } catch (UnsupportedByBackendException $refusal) {
            self::assertStringContainsString($name, $refusal->getMessage());
            self::assertStringContainsString('journal', $refusal->getMessage());
        }

        self::assertSame([WorkflowExecutionFailed::class], array_map(get_class(...), iterator_to_array($store->readStream($id), false)));
    }

    #[DataProvider('unsupportedOptions')]
    public function testTheRunnerRethrowsTheRefusalAndJournalsTheFailure(ContinueAsNewOptions $options, string $name): void
    {
        $store = new InMemoryEventStore();
        $id = ExecutionId::generate();
        $runner = new InMemoryWorkflowRunner($store, new InMemoryActivityTransport(), new RegistryActivityExecutor());

        try {
            $runner->run($id, static fn(WorkflowEnvironment $wf): never => $wf->continueAsNew('Next', [], $options));
            self::fail('The runner must rethrow the refusal.');
        } catch (UnsupportedByBackendException $refusal) {
            self::assertStringContainsString($name, $refusal->getMessage());
        }

        self::assertContains(WorkflowExecutionFailed::class, array_map(get_class(...), iterator_to_array($store->readStream($id), false)));
    }

    #[DataProvider('unsupportedOptions')]
    public function testTheAsyncHandlerJournalsTheRefusalBeforeRethrowingIt(ContinueAsNewOptions $options, string $name): void
    {
        RefusedContinuationWorkflow::$options = $options;
        $store = new InMemoryEventStore();
        $metadata = new InMemoryWorkflowMetadataStore();
        $metadata->save(ExecutionId::fromString('exec-refused'), RefusedContinuationWorkflow::class, []);
        $registry = new WorkflowRegistry();
        $registry->registerClass(RefusedContinuationWorkflow::class);
        $handler = new ResumeWorkflowHandler(
            new ExecutionEngine($store, new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true)),
            $registry,
            $metadata,
            new NullWorkflowResumeDispatcher(),
            $store,
            new InMemoryChildWorkflowParentLinkStore(),
            new class implements WorkflowTimerDispatcher {
                public function dispatchTimerFire(ExecutionId $executionId, int $delayMs = 0): void {}
            },
            new WorkflowDefinitionLoader(),
        );

        try {
            $handler(new ResumeWorkflowMessage('exec-refused'));
            self::fail('The handler must rethrow the refusal.');
        } catch (UnsupportedByBackendException $refusal) {
            self::assertStringContainsString($name, $refusal->getMessage());
        }

        self::assertSame(
            [WorkflowExecutionFailed::class],
            array_map(get_class(...), iterator_to_array($store->readStream(ExecutionId::fromString('exec-refused')), false)),
            'The run is closed as completed: the journal must say how it ended.',
        );
    }

    public function testNoOptionAndEmptyOptionsAreAccepted(): void
    {
        foreach ([null, ContinueAsNewOptions::new()] as $options) {
            $store = new InMemoryEventStore();
            $id = ExecutionId::generate();

            try {
                (new EventStoreWorkflowLifecycle($store))->onContinuedAsNew($id, new ContinueAsNewRequested('Next', [], $options));
                self::fail('A continue-as-new always ends in its request.');
            } catch (ContinueAsNewRequested) {
            }

            self::assertInstanceOf(WorkflowContinuedAsNew::class, iterator_to_array($store->readStream($id), false)[0]);
        }
    }
}

#[AsWorkflow(name: 'test.refused-continuation')]
final class RefusedContinuationWorkflow
{
    public static ?ContinueAsNewOptions $options = null;

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): never
    {
        $env->continueAsNew('Next', [], self::$options);
    }
}
