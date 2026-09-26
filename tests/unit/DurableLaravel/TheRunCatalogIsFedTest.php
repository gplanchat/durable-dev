<?php

declare(strict_types=1);

namespace unit\DurableLaravel;

use Gplanchat\Bridge\Illuminate\Queue\ResumeLock;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Laravel\Queue\ResumeDeferral;
use Gplanchat\Durable\Laravel\Queue\ResumeWorkflowJob;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Illuminate\Cache\ArrayStore;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use unit\DurableLaravel\Fixtures\AwaitApprovalWorkflow;
use unit\DurableLaravel\Fixtures\FakeQueue;
use unit\DurableLaravel\Fixtures\FakeQueueFactory;
use unit\DurableLaravel\Fixtures\GreetingWorkflow;

/**
 * The run catalog is bound on Laravel, and until #458 nothing wrote to it: the stores were bound
 * without the projections that name a run, record its pickup and its outcome (DUR037, DUR043).
 */
final class TheRunCatalogIsFedTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function journalBackends(): iterable
    {
        yield 'illuminate' => ['illuminate'];
        yield 'memory' => ['memory'];
    }

    #[DataProvider('journalBackends')]
    public function testADispatchedRunIsListedByNameWaitingForAWorker(string $backend): void
    {
        $app = $this->registered($backend);

        $this->dispatch($app, $backend, 'exec-1', GreetingWorkflow::class);

        $page = $app->make(WorkflowRunCatalogInterface::class)->listRuns();
        self::assertCount(1, $page->runs, 'the run is named as soon as it is dispatched');
        // The dispatcher names the run by its alias (#258); the memory case writes the metadata itself.
        self::assertSame('illuminate' === $backend ? 'Greeting' : GreetingWorkflow::class, $page->runs[0]->workflowName);
        self::assertSame(WorkflowRunStatus::Running, $page->runs[0]->status);
        self::assertTrue($page->tellsWaitingForWorker);
        self::assertNotNull($page->runs[0]->waitingForWorkerSince, 'nobody took it yet');
    }

    /**
     * The run suspends on a signal with no deadline and appends nothing: only the resume handler
     * can say a worker took it, so this fails if the handler is not given the pickup projection.
     */
    #[DataProvider('journalBackends')]
    public function testARunAWorkerTookIsNoLongerWaitingWhileItStillRuns(string $backend): void
    {
        $app = $this->registered($backend);
        $this->dispatch($app, $backend, 'exec-2', AwaitApprovalWorkflow::class);

        $this->resume($app, 'exec-2');

        $run = $app->make(WorkflowRunCatalogInterface::class)->listRuns()->runs[0];
        self::assertSame(WorkflowRunStatus::Running, $run->status, 'still waiting for its signal');
        self::assertNull($run->waitingForWorkerSince);
    }

    #[DataProvider('journalBackends')]
    public function testAnEndedRunIsListedWithItsOutcome(string $backend): void
    {
        $app = $this->registered($backend);
        $this->dispatch($app, $backend, 'exec-3', GreetingWorkflow::class);

        $this->resume($app, 'exec-3');

        self::assertSame(WorkflowRunStatus::Completed, $app->make(WorkflowRunCatalogInterface::class)->listRuns()->runs[0]->status);
    }

    public function testTheHistoryIsReadFromTheConfiguredJournalTable(): void
    {
        $app = $this->registered('illuminate', ['events' => 'wf_journal']);
        $this->dispatch($app, 'illuminate', 'exec-4', GreetingWorkflow::class);
        $this->resume($app, 'exec-4');

        $catalog = $app->make(WorkflowRunCatalogInterface::class);

        self::assertNotSame([], $catalog->readHistory($catalog->listRuns()->runs[0]), 'the journal lives in wf_journal, not durable_events');
    }

    /** @param array<string, string> $tables */
    private function registered(string $backend, array $tables = []): Container
    {
        $app = new Container();
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app->instance(Connection::class, $capsule->getConnection());
        $app->instance(QueueFactory::class, new FakeQueueFactory(new FakeQueue()));
        $app->instance('config', new \ArrayObject(
            ['durable' => ['backend' => $backend, 'tables' => $tables, 'workflows' => [GreetingWorkflow::class, AwaitApprovalWorkflow::class]]],
            \ArrayObject::ARRAY_AS_PROPS,
        ));
        (new DurableServiceProvider($app))->register();

        return $app;
    }

    /**
     * Through the dispatcher on illuminate. On memory the dispatcher is the null one, which saves
     * nothing, so the metadata is saved as the dispatcher would.
     */
    private function dispatch(Container $app, string $backend, string $executionId, string $workflowType): void
    {
        if ('illuminate' === $backend) {
            $app->make(WorkflowResumeDispatcher::class)->dispatchNewWorkflowRun($executionId, $workflowType, []);

            return;
        }

        $app->make(WorkflowMetadataStore::class)->save($executionId, $workflowType, []);
    }

    private function resume(Container $app, string $executionId): void
    {
        (new ResumeWorkflowJob(new ResumeWorkflowMessage($executionId)))->handle(
            $app->make(ResumeWorkflowHandler::class),
            new ResumeLock(new ArrayStore()),
            new FakeQueueFactory(new FakeQueue()),
            new ResumeDeferral(),
        );
    }
}
