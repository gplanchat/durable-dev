<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel;

use Gplanchat\Bridge\Illuminate\Queue\ResumeLock;
use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateChildWorkflowParentLinkStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateEventStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowMetadataStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowRunCatalog;
use Gplanchat\Durable\Laravel\Queue\LaravelActivityTransport;
use Gplanchat\Durable\Laravel\Queue\LaravelWorkflowResumeDispatcher;
use Gplanchat\Durable\Laravel\Workflow\DeclaredWorkflowTypes;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowRegistry;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Connection;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\ServiceProvider;

/**
 * Lie les quatre ports de stockage depuis un seul fichier de configuration.
 *
 * **Un choix de backend lie les quatre ports ensemble.** Un journal sur un backend et des
 * métadonnées sur un autre n'est pas une configuration, c'est une panne — d'où un seul `match`
 * plutôt que quatre réglages indépendants.
 *
 * Ce provider est celui du paquet d'**intégration**. Celui du pont,
 * `Gplanchat\Bridge\Illuminate\DurableIlluminateServiceProvider`, ne dit que où sont ses
 * migrations, et les deux se chargent côte à côte sans se marcher dessus.
 */
final class DurableServiceProvider extends ServiceProvider
{
    private const BACKENDS = ['illuminate', 'memory'];

    public function register(): void
    {
        $config = $this->durableConfig();
        $backend = $config['backend'] ?? 'illuminate';

        if (!\in_array($backend, self::BACKENDS, true)) {
            // Nommer les deux : un message qui dit seulement « backend inconnu » fait ouvrir le code.
            throw new \InvalidArgumentException(\sprintf(
                'Durable: unknown backend "%s". This package serves %s.',
                \is_scalar($backend) ? (string) $backend : \get_debug_type($backend),
                '"' . implode('", "', self::BACKENDS) . '"',
            ));
        }

        $backend === 'illuminate' ? $this->bindIlluminate($config) : $this->bindInMemory();
        $this->bindActivityTransport($backend, $config);
        $this->bindResumeLock($config);
        $this->bindWorkflowRegistry($config);
    }

    public function boot(): void
    {
        // `ServiceProvider::$app` est documenté comme l'application complète, et ce paquet tient
        // à ce que ce soit faux : un conteneur nu doit pouvoir enregistrer ces liaisons, dans un
        // worker autonome comme dans un test. Seule la publication a besoin de `configPath()`.
        if (method_exists($this->app, 'configPath')) {
            $this->publishes(
                [__DIR__ . '/config/durable.php' => $this->app->configPath('durable.php')],
                'durable-config',
            );
        }

        // §1.3 : `null` ne verrouille jamais, dans aucun déploiement. Le refus est donc sans risque
        // au démarrage, là où `array` — correct dans un seul processus, et cache par défaut de
        // l'environnement de test — ne peut être jugé que par la commande de worker.
        if ($this->app->bound('cache')) {
            $this->refuseALockStoreThatCannotLock();
        }

        // Et `sync` exécute le job sur place : une reprise qui en dispatche une autre récurserait
        // dans le même processus. Le pendant Symfony s'en protège par un
        // DispatchAfterCurrentBusStamp ; ici, c'est la connexion qui doit être une vraie file.
        if ($this->app->bound('queue')) {
            $this->refuseAQueueThatRunsInline();
        }
    }

    /** @return array<string, mixed> */
    private function durableConfig(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = require __DIR__ . '/config/durable.php';

        if (!$this->app->bound('config')) {
            return $defaults;
        }

        /** @var array<string, mixed> $configured */
        $configured = $this->app['config']['durable'] ?? [];

        return array_replace_recursive($defaults, $configured);
    }

    /** @param array<string, mixed> $config */
    private function bindIlluminate(array $config): void
    {
        $tables = $config['tables'] ?? [];

        if (!$this->app->bound(Connection::class)) {
            $this->app->singleton(
                Connection::class,
                fn($app) => $app->make('db')->connection($config['connection'] ?? null),
            );
        }

        $this->app->singleton(DurableSchema::class, fn($app) => new DurableSchema(
            $app->make(Connection::class),
            $tables['events'] ?? 'durable_events',
            $tables['metadata'] ?? 'durable_workflow_metadata',
            $tables['parent_links'] ?? 'durable_child_workflow_parent_link',
            $tables['runs'] ?? 'durable_workflow_runs',
        ));

        $this->app->singleton(EventStoreInterface::class, fn($app) => new IlluminateEventStore(
            $app->make(Connection::class),
            $app->make(DurableSchema::class),
            $tables['events'] ?? 'durable_events',
        ));

        $this->app->singleton(WorkflowMetadataStore::class, fn($app) => new IlluminateWorkflowMetadataStore(
            $app->make(Connection::class),
            $app->make(DurableSchema::class),
            $tables['metadata'] ?? 'durable_workflow_metadata',
        ));

        $this->app->singleton(ChildWorkflowParentLinkStoreInterface::class, fn($app) => new IlluminateChildWorkflowParentLinkStore(
            $app->make(Connection::class),
            $app->make(DurableSchema::class),
            $tables['parent_links'] ?? 'durable_child_workflow_parent_link',
        ));

        $this->app->singleton(WorkflowRunCatalogInterface::class, fn($app) => new IlluminateWorkflowRunCatalog(
            $app->make(Connection::class),
            $app->make(DurableSchema::class),
            $tables['runs'] ?? 'durable_workflow_runs',
        ));
    }

    private function bindInMemory(): void
    {
        $this->app->singleton(EventStoreInterface::class, fn() => new InMemoryEventStore());
        $this->app->singleton(WorkflowMetadataStore::class, fn() => new InMemoryWorkflowMetadataStore());
        $this->app->singleton(ChildWorkflowParentLinkStoreInterface::class, fn() => new InMemoryChildWorkflowParentLinkStore());
        $this->app->singleton(
            WorkflowRunCatalogInterface::class,
            fn($app) => new InMemoryWorkflowRunCatalog($app->make(EventStoreInterface::class)),
        );
    }

    /**
     * Le transport suit le backend, comme les quatre magasins : « memory » ne sort pas du
     * processus, « illuminate » voyage sur la file que l'application draine déjà.
     *
     * @param array<string, mixed> $config
     */
    private function bindActivityTransport(string $backend, array $config): void
    {
        if ($backend !== 'illuminate') {
            $this->app->singleton(ActivityTransportInterface::class, fn() => new InMemoryActivityTransport());
            $this->app->singleton(WorkflowResumeDispatcher::class, fn() => new NullWorkflowResumeDispatcher());

            return;
        }

        /** @var array<string, mixed> $queue */
        $queue = $config['queue'] ?? [];

        $this->app->singleton(ActivityTransportInterface::class, fn($app) => new LaravelActivityTransport(
            $app->make(QueueFactory::class),
            $queue['connection'] ?? null,
            $queue['name'] ?? null,
        ));

        $this->app->singleton(WorkflowResumeDispatcher::class, fn($app) => new LaravelWorkflowResumeDispatcher(
            $app->make(QueueFactory::class),
            $app->make(WorkflowMetadataStore::class),
            $queue['connection'] ?? null,
            $queue['name'] ?? null,
        ));
    }

    /** @param array<string, mixed> $config */
    private function bindWorkflowRegistry(array $config): void
    {
        /** @var list<class-string> $declared */
        $declared = $config['workflows'] ?? [];

        $this->app->singleton(WorkflowRegistry::class, static function () use ($declared): WorkflowRegistry {
            $registry = new WorkflowRegistry();

            foreach ($declared as $workflowClass) {
                // Le registre indexe chaque classe deux fois : sous le nom que son attribut déclare
                // et sous son FQCN. Une reprise qui n'a que l'un des deux résout quand même.
                $registry->registerClass($workflowClass);
            }

            return $registry;
        });

        $this->app->singleton(DeclaredWorkflowTypes::class, fn($app) => new DeclaredWorkflowTypes(
            $app->make(WorkflowRegistry::class),
            $declared,
        ));
    }

    /** @param array<string, mixed> $config */
    private function bindResumeLock(array $config): void
    {
        /** @var array<string, mixed> $lock */
        $lock = $config['lock'] ?? [];

        $this->app->singleton(ResumeLock::class, fn($app) => new ResumeLock(
            $app->make('cache')->store($lock['store'] ?? null)->getStore(),
            (int) ($lock['ttl'] ?? 300),
            (int) ($lock['wait'] ?? 10),
        ));
    }

    private function refuseAQueueThatRunsInline(): void
    {
        $config = $this->durableConfig();
        if (($config['backend'] ?? 'illuminate') !== 'illuminate') {
            return;
        }

        /** @var array<string, mixed> $queue */
        $queue = $config['queue'] ?? [];
        $name = $queue['connection'] ?? null;

        if ($this->app->make('queue')->connection($name) instanceof SyncQueue) {
            throw new \InvalidArgumentException(\sprintf(
                'Durable: the "%s" queue connection runs jobs inline, so a resume that dispatches '
                . 'another resume recurses in the same process until the stack ends. Use a real '
                . 'queue connection — database, redis, sqs, beanstalkd.',
                $name ?? 'default',
            ));
        }
    }

    private function refuseALockStoreThatCannotLock(): void
    {
        /** @var array<string, mixed> $lock */
        $lock = $this->durableConfig()['lock'] ?? [];
        $name = $lock['store'] ?? null;

        if ($this->app->make('cache')->store($name)->getStore() instanceof NullStore) {
            throw new \InvalidArgumentException(\sprintf(
                'Durable: the "%s" cache store grants every lock, so two workers would replay the '
                . 'same execution and its activities would run twice. Use database, redis, '
                . 'memcached, dynamodb or file.',
                $name ?? 'default',
            ));
        }
    }
}
