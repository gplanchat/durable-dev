<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Laravel;

use Gplanchat\Bridge\Illuminate\Queue\ResumeLock;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Laravel\Queue\ResumeDeferral;
use Gplanchat\Durable\Laravel\Queue\ResumeWorkflowJob;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;
use unit\DurableLaravel\Fixtures\FakeQueue;
use unit\DurableLaravel\Fixtures\FakeQueueFactory;
use unit\DurableLaravel\Fixtures\GreetingWorkflow;

/**
 * §4 — une reprise à la fois par exécution, dans la forme que §1.2 a mesurée.
 */
final class OneResumeAtATimeTest extends TestCase
{
    public function testAResumeWhoseTurnIsTakenDefersInsteadOfReplaying(): void
    {
        $app = $this->container([GreetingWorkflow::class]);
        (new DurableServiceProvider($app))->register();
        $metadata = $app->make(WorkflowMetadataStore::class);
        $metadata->save('exec-1', GreetingWorkflow::class, []);

        $store = new ArrayStore();
        // Un autre worker tient déjà le tour de cette exécution.
        $store->lock(ResumeLock::nameFor('exec-1'), 300)->get();

        $queue = new FakeQueue();
        (new ResumeWorkflowJob(new ResumeWorkflowMessage('exec-1')))->handle(
            $app->make(ResumeWorkflowHandler::class),
            new ResumeLock($store),
            new FakeQueueFactory($queue),
            new ResumeDeferral(2),
        );

        // Rien n'a été rejoué…
        self::assertFalse($metadata->get('exec-1')['completed'] ?? false);
        // …et la reprise est reposée pour plus tard, avec son compteur de reports.
        self::assertCount(1, $queue->pushed);
        self::assertSame(2, $queue->pushed[0]['delay']);
        self::assertSame(1, $queue->pushed[0]['job']->deferrals);
    }

    public function testAFreeTurnReplaysAndQueuesNothing(): void
    {
        $app = $this->container([GreetingWorkflow::class]);
        (new DurableServiceProvider($app))->register();
        $metadata = $app->make(WorkflowMetadataStore::class);
        $metadata->save('exec-2', GreetingWorkflow::class, []);

        $queue = new FakeQueue();
        (new ResumeWorkflowJob(new ResumeWorkflowMessage('exec-2')))->handle(
            $app->make(ResumeWorkflowHandler::class),
            new ResumeLock(new ArrayStore()),
            new FakeQueueFactory($queue),
            new ResumeDeferral(),
        );

        self::assertTrue($metadata->get('exec-2')['completed'] ?? false);
        self::assertSame([], $queue->pushed, 'un tour libre ne se repose pas');
    }

    public function testTheLockIsReleasedSoTheNextResumeGetsItsTurn(): void
    {
        $app = $this->container([GreetingWorkflow::class]);
        (new DurableServiceProvider($app))->register();
        $app->make(WorkflowMetadataStore::class)->save('exec-3', GreetingWorkflow::class, []);

        $store = new ArrayStore();
        (new ResumeWorkflowJob(new ResumeWorkflowMessage('exec-3')))->handle(
            $app->make(ResumeWorkflowHandler::class),
            new ResumeLock($store),
            new FakeQueueFactory(new FakeQueue()),
            new ResumeDeferral(),
        );

        // Un verrou rendu, pas un verrou tenu jusqu'au TTL : sinon la reprise suivante
        // attendrait cinq minutes pour rien.
        self::assertTrue($store->lock(ResumeLock::nameFor('exec-3'), 300)->get());
    }

    public function testEndlessDeferralGivesUpLoudly(): void
    {
        $queue = new FakeQueue();
        $job = new ResumeWorkflowJob(new ResumeWorkflowMessage('exec-hot'), 50);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('gave up resuming exec-hot');
        $this->expectExceptionMessage('50 consecutive');

        (new ResumeDeferral(1, 50))->defer($job, new FakeQueueFactory($queue));
    }

    public function testAPerProcessLockStoreIsRefusedUnderTheIlluminateBackend(): void
    {
        $app = $this->illuminateContainer();
        $app->instance('cache', $this->cacheReturning(new ArrayStore()));
        $provider = new DurableServiceProvider($app);
        $provider->register();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only excludes inside one process');

        $provider->boot();
    }

    public function testThatSameStoreIsFineForTheInMemoryBackend(): void
    {
        // §1.3 : `array` est le cache de test par défaut de Laravel, et exclure dans un seul
        // processus est exactement ce qu'un test veut.
        $app = $this->container();
        $app->instance('cache', $this->cacheReturning(new ArrayStore()));
        $provider = new DurableServiceProvider($app);
        $provider->register();
        $provider->boot();

        self::assertTrue(true, 'aucun refus sous le backend memory');
    }

    public function testAStoreThatGrantsEveryLockIsStillRefused(): void
    {
        $app = $this->container();
        $app->instance('cache', $this->cacheReturning(new NullStore()));
        $provider = new DurableServiceProvider($app);
        $provider->register();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('grants every lock');

        $provider->boot();
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

    private function illuminateContainer(): Container
    {
        $app = new Container();
        $app->instance('config', new \ArrayObject(
            [
                'durable' => ['backend' => 'illuminate', 'workflows' => []],
                'queue' => ['default' => 'database', 'connections' => ['database' => ['driver' => 'database']]],
            ],
            \ArrayObject::ARRAY_AS_PROPS,
        ));
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app->instance(Connection::class, $capsule->getConnection());

        return $app;
    }

    private function cacheReturning(object $store): object
    {
        return new class ($store) {
            public function __construct(private readonly object $store) {}

            public function store(?string $name = null): object
            {
                return new class ($this->store) {
                    public function __construct(private readonly object $store) {}

                    public function getStore(): object
                    {
                        return $this->store;
                    }
                };
            }
        };
    }
}
