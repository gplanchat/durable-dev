<?php

declare(strict_types=1);

namespace unit\DurableLaravel;

use Gplanchat\Bridge\Illuminate\Queue\ResumeLock;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Laravel\Queue\ResumeDeferral;
use Gplanchat\Durable\Laravel\Queue\ResumeWorkflowJob;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
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
    public function testADispatchedRunIsListedWaitingForAWorkerThenWithItsOutcome(string $backend): void
    {
        $app = $this->container($backend);
        (new DurableServiceProvider($app))->register();
        $catalog = $app->make(WorkflowRunCatalogInterface::class);

        $app->make(WorkflowMetadataStore::class)->save('exec-1', GreetingWorkflow::class, ['who' => 'Ada']);

        $page = $catalog->listRuns();
        self::assertCount(1, $page->runs, 'the run is named as soon as it is dispatched');
        self::assertSame(WorkflowRunStatus::Running, $page->runs[0]->status);
        self::assertTrue($page->tellsWaitingForWorker);
        self::assertNotNull($page->runs[0]->waitingForWorkerSince, 'nobody took it yet');

        (new ResumeWorkflowJob(new ResumeWorkflowMessage('exec-1')))->handle(
            $app->make(ResumeWorkflowHandler::class),
            new ResumeLock(new ArrayStore()),
            new FakeQueueFactory(new FakeQueue()),
            new ResumeDeferral(),
        );

        $run = $catalog->listRuns()->runs[0];
        self::assertSame(WorkflowRunStatus::Completed, $run->status, 'the outcome comes from the journal');
        self::assertNull($run->waitingForWorkerSince);
    }

    private function container(string $backend): Container
    {
        $app = new Container();
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app->instance(Connection::class, $capsule->getConnection());
        $app->instance(QueueFactory::class, new FakeQueueFactory(new FakeQueue()));
        $app->instance('config', new \ArrayObject(
            ['durable' => ['backend' => $backend, 'workflows' => [GreetingWorkflow::class]]],
            \ArrayObject::ARRAY_AS_PROPS,
        ));

        return $app;
    }
}
