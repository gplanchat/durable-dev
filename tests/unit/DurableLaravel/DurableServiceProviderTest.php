<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Laravel;

use Gplanchat\Bridge\Illuminate\Queue\ResumeLock;
use Gplanchat\Bridge\Illuminate\Store\IlluminateChildWorkflowParentLinkStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateEventStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowMetadataStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowRunCatalog;
use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The Laravel integration's service provider, with no Laravel application around it.
 *
 * A bare container is enough, and that is deliberate: what the service provider does has to hold
 * in a standalone worker and in a test, not only under a full kernel — the lesson `ResumeLock`
 * has already learnt by avoiding `Lock::block()` and its global `now()`.
 */
final class DurableServiceProviderTest extends TestCase
{
    public function testItBindsTheFourPortsToTheIlluminateStores(): void
    {
        $app = $this->containerWithConnection(['backend' => 'illuminate']);

        (new DurableServiceProvider($app))->register();

        self::assertInstanceOf(IlluminateEventStore::class, $app->make(EventStoreInterface::class));
        self::assertInstanceOf(IlluminateWorkflowMetadataStore::class, $app->make(WorkflowMetadataStore::class));
        self::assertInstanceOf(IlluminateChildWorkflowParentLinkStore::class, $app->make(ChildWorkflowParentLinkStoreInterface::class));
        self::assertInstanceOf(IlluminateWorkflowRunCatalog::class, $app->make(WorkflowRunCatalogInterface::class));
    }

    public function testAChoiceOfBackendBindsEveryPortTogether(): void
    {
        $app = $this->containerWithConnection(['backend' => 'memory']);

        (new DurableServiceProvider($app))->register();

        // No port stays on the other backend: an in-memory journal under a SQL catalog is not a
        // configuration, it is a breakdown.
        self::assertInstanceOf(InMemoryEventStore::class, $app->make(EventStoreInterface::class));
        self::assertInstanceOf(InMemoryWorkflowMetadataStore::class, $app->make(WorkflowMetadataStore::class));
        self::assertInstanceOf(InMemoryChildWorkflowParentLinkStore::class, $app->make(ChildWorkflowParentLinkStoreInterface::class));
        self::assertInstanceOf(InMemoryWorkflowRunCatalog::class, $app->make(WorkflowRunCatalogInterface::class));
    }

    public function testABackendItCannotServeIsRefusedByNameAtRegistration(): void
    {
        $app = $this->containerWithConnection(['backend' => 'dbal']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown backend "dbal"');
        $this->expectExceptionMessage('"illuminate", "memory"');

        (new DurableServiceProvider($app))->register();
    }

    public function testTheTableNamesComeFromTheConfiguration(): void
    {
        $app = $this->containerWithConnection([
            'backend' => 'illuminate',
            'tables' => ['events' => 'wf_journal'],
        ]);

        (new DurableServiceProvider($app))->register();
        $app->make(EventStoreInterface::class);

        self::assertSame('wf_journal', (new \ReflectionProperty(IlluminateEventStore::class, 'table'))
            ->getValue($app->make(EventStoreInterface::class)));
    }

    public function testALockStoreThatGrantsEveryLockIsRefusedAtBoot(): void
    {
        $app = $this->containerWithConnection(['backend' => 'memory']);
        $app->instance('cache', $this->cacheManagerReturning(new NullStore()));

        $provider = new DurableServiceProvider($app);
        $provider->register();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('grants every lock');

        $provider->boot();
    }

    public function testALockStoreThatLocksIsAccepted(): void
    {
        $app = $this->containerWithConnection(['backend' => 'memory']);
        // `array` only excludes inside one process, and it is the worker command that will judge
        // that — boot only refuses what is right in no deployment at all (§1.3).
        $app->instance('cache', $this->cacheManagerReturning(new ArrayStore()));

        $provider = new DurableServiceProvider($app);
        $provider->register();
        $provider->boot();

        self::assertInstanceOf(ResumeLock::class, $app->make(ResumeLock::class));
    }

    /** @param array<string, mixed> $durable */
    private function containerWithConnection(array $durable): Container
    {
        $app = new Container();

        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app->instance(Connection::class, $capsule->getConnection());

        $app->instance('config', new \ArrayObject(['durable' => $durable], \ArrayObject::ARRAY_AS_PROPS));

        return $app;
    }

    private function cacheManagerReturning(object $store): object
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
