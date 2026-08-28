<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

/**
 * La reprise d'une exécution, sans une ligne de Symfony.
 *
 * Cette orchestration vivait dans le bundle : 138 lignes dont 15 imports du cœur et 6 de Symfony,
 * ces six-là servant à **deux** choses — un identifiant v7, que `ExecutionId` sait déjà fabriquer,
 * et « publier le réveil des minuteries après l'unité de travail courante », qui est un port.
 *
 * Six hôtes du sélecteur ne passent pas par le bundle. Le coût de la laisser là n'était pas
 * 279 lignes, c'était 279 par hôte, plus la divergence à la première correction.
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
     * Ce que le port remplace : le `messageBus->dispatch(new Envelope(…, [DispatchAfterCurrentBusStamp]))`
     * du bundle. Un hôte sans bus doit pouvoir répondre à la même question — « réveille les
     * minuteries de cette exécution, après le travail courant, dans n millisecondes ».
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
