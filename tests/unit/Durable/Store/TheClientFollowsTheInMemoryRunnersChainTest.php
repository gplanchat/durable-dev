<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Exception\WorkflowSuccessorNotStarted;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\LinkedChildFinalizer;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Query\ReadOnlyReplay;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowClient;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\ProjectingEventStore;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\CounterWorkflow;

/**
 * Magento's in-memory backend records the start of the first run in the run catalogue
 * (`MagentoRuntime::start()`), and `InMemoryWorkflowRunner` runs the next runs of a chain without
 * recording them. The catalogue is what the client port answers from, so the next run does not
 * exist for it: the stub's chain calls fail naming both ids (task 4.4) instead of waiting.
 */
final class TheClientFollowsTheInMemoryRunnersChainTest extends TestCase
{
    private InMemoryEventStore $events;
    private InMemoryWorkflowClient $client;

    public function testEveryChainCallNamesTheEndedRunAndItsUnrecordedSuccessor(): void
    {
        $this->runCounterChain();
        $first = ExecutionId::fromString('counter-0');
        $successor = $this->continuationOf('counter-0');
        self::assertNotNull($successor, 'The runner journals the continuation.');

        foreach (['result', 'cancel', 'terminate'] as $operation) {
            try {
                match ($operation) {
                    'result' => $this->client->result($first, Duration::seconds(1)),
                    'cancel' => $this->client->cancel($first),
                    default => $this->client->terminate($first),
                };
                self::fail($operation . ' must fail.');
            } catch (WorkflowSuccessorNotStarted $e) {
                self::assertSame('counter-0', $e->executionId);
                self::assertSame($successor, $e->successorId);
            }
        }
    }

    private function continuationOf(string $id): ?string
    {
        foreach ($this->events->readStream(ExecutionId::fromString($id)) as $event) {
            if ($event instanceof WorkflowContinuedAsNew) {
                return $event->newExecutionId()?->toString();
            }
        }

        return null;
    }

    private function runCounterChain(): void
    {
        $this->events = new InMemoryEventStore();
        $catalog = new InMemoryWorkflowRunCatalog($this->events);
        $journal = new ProjectingEventStore($this->events, $catalog);
        $metadata = new ProjectingWorkflowMetadataStore(new InMemoryWorkflowMetadataStore(), $catalog);
        $registry = new WorkflowRegistry();
        $registry->registerClass(CounterWorkflow::class);
        $activities = new RegistryActivityExecutor();
        $runner = new InMemoryWorkflowRunner($journal, new InMemoryActivityTransport(), $activities, 0, $registry);
        $engine = new ExecutionEngine(
            $journal,
            new ExecutionRuntime($journal, new InMemoryActivityTransport(), $activities, 0, null, true),
            workflowDefinitionLoader: new WorkflowDefinitionLoader(),
        );
        $dispatcher = new NullWorkflowResumeDispatcher();
        $this->client = new InMemoryWorkflowClient(
            $metadata,
            $dispatcher,
            $catalog,
            $journal,
            new ReadOnlyReplay($engine, $journal, $metadata, $registry),
            new LinkedChildFinalizer($journal, $dispatcher, new InMemoryChildWorkflowParentLinkStore()),
        );

        $first = ExecutionId::fromString('counter-0');
        $catalog->recordStart($first, 'counter');
        $runner->run($first, $registry->getHandler(CounterWorkflow::class, ['n' => 0]));
    }
}
