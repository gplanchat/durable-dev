<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Laravel;

use Gplanchat\Bridge\Illuminate\Queue\ResumeLock;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Laravel\Queue\LaravelWorkflowTimerDispatcher;
use Gplanchat\Durable\Laravel\Queue\ResumeDeferral;
use Gplanchat\Durable\Laravel\Queue\ResumeWorkflowJob;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Illuminate\Cache\ArrayStore;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;
use unit\DurableLaravel\Fixtures\FakeQueue;
use unit\DurableLaravel\Fixtures\FakeQueueFactory;
use unit\DurableLaravel\Fixtures\GreetingWorkflow;

/**
 * What replays an execution, and the timer that wakes it up.
 */
final class ResumePathTest extends TestCase
{
    public function testTheContainerAssemblesTheCoreResumeHandler(): void
    {
        $app = $this->container();
        (new DurableServiceProvider($app))->register();

        // Eight collaborators, all resolved from the configuration: this package assembles the
        // core's handler, it does not write a second one.
        self::assertInstanceOf(ResumeWorkflowHandler::class, $app->make(ResumeWorkflowHandler::class));
    }

    public function testAResumeJobActuallyReplaysTheExecution(): void
    {
        // `ResumeWorkflowHandler` is `final`: no double. So it is given a real workflow and
        // real in-memory stores, and the result is what gets looked at — which proves more than
        // a spy would, since it is the core's replay that runs.
        $app = $this->container([GreetingWorkflow::class]);
        (new DurableServiceProvider($app))->register();

        $metadata = $app->make(WorkflowMetadataStore::class);
        $metadata->save('exec-9', GreetingWorkflow::class, ['who' => 'Ada']);

        (new ResumeWorkflowJob(new ResumeWorkflowMessage('exec-9')))->handle(
            $app->make(ResumeWorkflowHandler::class),
            new ResumeLock(new ArrayStore()),
            new FakeQueueFactory(new FakeQueue()),
            new ResumeDeferral(),
        );

        self::assertTrue($metadata->get('exec-9')['completed'] ?? false);
    }

    public function testATimerIsADeferredResumeOnTheQueuesOwnDelay(): void
    {
        $queue = new FakeQueue();
        $timers = new LaravelWorkflowTimerDispatcher(new FakeQueueFactory($queue), null, 'durable');

        $timers->dispatchTimerFire('exec-1', 2400);

        self::assertInstanceOf(ResumeWorkflowJob::class, $queue->pushed[0]['job']);
        // Rounded up: a workflow woken too early resumes before its due date.
        self::assertSame(3, $queue->pushed[0]['delay']);
        self::assertSame('durable', $queue->pushed[0]['queue']);
    }

    public function testATimerWithoutDelayIsAPlainResume(): void
    {
        $queue = new FakeQueue();
        (new LaravelWorkflowTimerDispatcher(new FakeQueueFactory($queue)))->dispatchTimerFire('exec-1');

        self::assertNull($queue->pushed[0]['delay']);
    }

    public function testTheTimerPortIsBoundToTheQueueBackedOne(): void
    {
        // `illuminate` backend: the timer is the queue-backed one.
        $app = new Container();
        $app->instance('config', new \ArrayObject(
            ['durable' => ['backend' => 'illuminate', 'workflows' => []]],
            \ArrayObject::ARRAY_AS_PROPS,
        ));
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app->instance(Connection::class, $capsule->getConnection());
        $app->instance(QueueFactory::class, new FakeQueueFactory(new FakeQueue()));
        (new DurableServiceProvider($app))->register();

        self::assertInstanceOf(LaravelWorkflowTimerDispatcher::class, $app->make(WorkflowTimerDispatcher::class));
    }

    /** @param list<class-string> $workflows */
    private function container(array $workflows = []): Container
    {
        $app = new Container();
        $app->instance('config', new \ArrayObject(
            ['durable' => ['backend' => 'memory', 'workflows' => $workflows]],
            \ArrayObject::ARRAY_AS_PROPS,
        ));

        return $app;
    }
}
