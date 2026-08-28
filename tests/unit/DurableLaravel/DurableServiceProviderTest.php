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
 * Le provider de l'intégration Laravel, sans application Laravel autour.
 *
 * Un conteneur nu suffit, et c'est délibéré : ce que le provider fait doit être vrai dans un
 * worker autonome et dans un test, pas seulement sous un kernel complet — la leçon que
 * `ResumeLock` a déjà apprise en évitant `Lock::block()` et son `now()` global.
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

        // Aucun port ne reste sur l'autre backend : un journal en mémoire sous un catalogue SQL
        // n'est pas une configuration, c'est une panne.
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
        // `array` n'exclut que dans un processus, et c'est la commande de worker qui le jugera —
        // le démarrage ne refuse que ce qui n'est juste dans aucun déploiement (§1.3).
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
