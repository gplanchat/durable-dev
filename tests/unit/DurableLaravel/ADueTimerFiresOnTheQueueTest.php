<?php

declare(strict_types=1);

namespace unit\DurableLaravel;

use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Laravel\Queue\FireWorkflowTimersJob;
use Gplanchat\Durable\Laravel\Queue\ResumeDeferral;
use Gplanchat\Durable\Laravel\Queue\ResumeLock;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\FrozenClock;
use unit\DurableLaravel\Fixtures\FakeQueue;
use unit\DurableLaravel\Fixtures\FakeQueueFactory;
use unit\DurableLaravel\Fixtures\NapWorkflow;

/**
 * #726: on the illuminate backend, a run that sleeps wakes up once its timer is due.
 *
 * Distributed, not inline: every job goes through the queue and a worker loop takes them one at a
 * time, the clock moving by each job's delay as `queue:work` would wait it out. Spike #709 found a
 * copy of the timer dispatch looping on a due timer that never fired; the bound below turns that
 * loop into a failure instead of a hang.
 */
final class ADueTimerFiresOnTheQueueTest extends TestCase
{
    public function testASleepingRunCompletesOnceItsTimerIsDue(): void
    {
        [$app, $clock, $queue] = $this->registered();

        $id = ExecutionId::fromString('exec-nap');
        $app->make(WorkflowResumeDispatcher::class)->dispatchNewWorkflowRun($id, NapWorkflow::class, []);

        $lock = new ResumeLock(new ArrayStore());
        for ($jobs = 0; [] !== $queue->pushed && $jobs < 20; ++$jobs) {
            $next = array_shift($queue->pushed);
            $clock->advance((float) ($next['delay'] ?? 0));
            $this->work($app, $queue, $lock, $next['job']);
        }

        self::assertTrue($app->make(WorkflowMetadataStore::class)->get($id)['completed'] ?? false, \sprintf('not completed after %d jobs', $jobs));
    }

    /**
     * Firing is a pass (DUR053). Without the resume lock, a duplicate firing supersedes the resume
     * the first one dispatched, finds nothing left to fire, and nobody wakes the run again.
     */
    public function testAFiringWhoseTurnIsTakenIsPutBackWithoutFiring(): void
    {
        [$app, $clock, $queue] = $this->registered();
        $id = ExecutionId::fromString('exec-nap');
        $app->make(WorkflowResumeDispatcher::class)->dispatchNewWorkflowRun($id, NapWorkflow::class, []);
        $store = new ArrayStore();
        $this->work($app, $queue, new ResumeLock($store), array_shift($queue->pushed)['job']);
        $firing = array_shift($queue->pushed)['job'];
        $clock->advance(2.0);

        $store->lock(ResumeLock::nameFor('exec-nap'), 300)->get();
        $this->work($app, $queue, new ResumeLock($store), $firing, new ResumeDeferral(3));

        foreach ($app->make(EventStoreInterface::class)->readStream($id) as $event) {
            self::assertNotInstanceOf(TimerCompleted::class, $event, 'fired while another worker held the turn');
        }
        self::assertCount(1, $queue->pushed);
        self::assertInstanceOf(FireWorkflowTimersJob::class, $queue->pushed[0]['job']);
        self::assertSame(3, $queue->pushed[0]['delay'], 'put back after durable.lock.backoff, like a resume');
    }

    /** @return array{Container, FrozenClock, FakeQueue} */
    private function registered(): array
    {
        $clock = new FrozenClock();
        $queue = new FakeQueue();
        $app = new Container();
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app->instance(Connection::class, $capsule->getConnection());
        $app->instance(QueueFactory::class, new FakeQueueFactory($queue));
        $app->instance('durable.clock', $clock);
        $app->instance('config', new \ArrayObject(
            ['durable' => ['backend' => 'illuminate', 'workflows' => [NapWorkflow::class]]],
            \ArrayObject::ARRAY_AS_PROPS,
        ));
        (new DurableServiceProvider($app))->register();

        return [$app, $clock, $queue];
    }

    /** One job taken by `queue:work`: its `handle()` resolved by the container, as Laravel does. */
    private function work(Container $app, FakeQueue $queue, ResumeLock $lock, object $job, ResumeDeferral $deferral = new ResumeDeferral()): void
    {
        $app->call([$job, 'handle'], ['lock' => $lock, 'queue' => new FakeQueueFactory($queue), 'deferral' => $deferral]);
    }
}
