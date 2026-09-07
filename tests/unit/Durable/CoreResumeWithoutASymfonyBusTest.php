<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Resuming an execution, without a line of Symfony.
 *
 * This orchestration lived in the bundle: 138 lines, of which 15 imports from the core and 6 from
 * Symfony, those six serving **two** things — a v7 identifier, which `ExecutionId` already knows
 * how to build, and "publish the timer wake after the current unit of work", which is a port.
 *
 * Six hosts of the selector do not go through the bundle. The cost of leaving it there was not
 * 279 lines, it was 279 per host, plus the divergence at the first fix.
 */
final class CoreResumeWithoutASymfonyBusTest extends TestCase
{
    public function testTheCoreResumesAnExecutionToCompletionOnItsOwn(): void
    {
        $store = new InMemoryEventStore();
        $metadata = new InMemoryWorkflowMetadataStore();
        $registry = new WorkflowRegistry();
        $registry->registerClass(ImmediateWorkflow::class);
        $metadata->save('exec-1', ImmediateWorkflow::class, ['name' => 'Ada']);

        $this->handlerFor($store, $metadata, $registry, new RecordingTimerDispatcher())(
            new ResumeWorkflowMessage('exec-1'),
        );

        self::assertTrue($metadata->get('exec-1')['completed'] ?? false);
    }

    /**
     * What the port replaces: the bundle's `messageBus->dispatch(new Envelope(…, [DispatchAfterCurrentBusStamp]))`.
     * A host without a bus must be able to answer the same question — "wake the timers of this
     * execution, after the current work, in n milliseconds".
     */
    public function testAnExecutionWaitingOnATimerAsksThePortAndNotAMessageBus(): void
    {
        $store = new InMemoryEventStore();
        $metadata = new InMemoryWorkflowMetadataStore();
        $registry = new WorkflowRegistry();
        $registry->registerClass(SleepingWorkflow::class);
        $metadata->save('exec-2', SleepingWorkflow::class, []);
        $timers = new RecordingTimerDispatcher();

        $this->handlerFor($store, $metadata, $registry, $timers)(new ResumeWorkflowMessage('exec-2'));

        self::assertSame(['exec-2'], $timers->executionIds);
    }

    private function handlerFor(
        InMemoryEventStore $store,
        InMemoryWorkflowMetadataStore $metadata,
        WorkflowRegistry $registry,
        WorkflowTimerDispatcher $timers,
    ): ResumeWorkflowHandler {
        $engine = new ExecutionEngine(
            $store,
            new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true),
        );

        return new ResumeWorkflowHandler(
            $engine,
            $registry,
            $metadata,
            new NullWorkflowResumeDispatcher(),
            $store,
            new InMemoryChildWorkflowParentLinkStore(),
            $timers,
            new WorkflowDefinitionLoader(),
        );
    }
}

final class RecordingTimerDispatcher implements WorkflowTimerDispatcher
{
    /** @var list<string> */
    public array $executionIds = [];

    public function dispatchTimerFire(string $executionId, int $delayMs = 0): void
    {
        $this->executionIds[] = $executionId;
    }
}

#[AsWorkflow(name: 'test.immediate')]
final class ImmediateWorkflow
{
    #[AsWorkflowMethod]
    public function run(string $name): string
    {
        return 'hello ' . $name;
    }
}

#[AsWorkflow(name: 'test.sleeping')]
final class SleepingWorkflow
{
    public function __construct(private readonly WorkflowEnvironment $environment) {}

    #[AsWorkflowMethod]
    public function run(): string
    {
        $this->environment->sleep(3600);

        return 'awake';
    }
}
