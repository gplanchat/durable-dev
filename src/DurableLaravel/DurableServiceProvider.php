<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel;

use Gplanchat\Bridge\Illuminate\Queue\ResumeLock;
use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateChildWorkflowParentLinkStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateEventStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowMetadataStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowRunCatalog;
use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\Durable\ActivityExecutor;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Laravel\Queue\LaravelActivityTransport;
use Gplanchat\Durable\Laravel\Queue\LaravelWorkflowResumeDispatcher;
use Gplanchat\Durable\Laravel\Queue\LaravelWorkflowTimerDispatcher;
use Gplanchat\Durable\Laravel\Workflow\DeclaredWorkflowTypes;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Port\NullWorkflowTimerDispatcher;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Connection;
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
        $this->bindResumePath($config);
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
        $this->refuseAQueueThatRunsInline();
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

    /**
     * Ce qui rejoue une exécution, et c'est le cœur qui le fait.
     *
     * `ResumeWorkflowHandler` a quitté le bundle Symfony pour le cœur pour qu'un hôte sans bus
     * puisse le rendre : ce paquet n'a donc qu'à l'assembler, pas à le réécrire. Un minuteur, lui,
     * est une reprise différée — la file porte le délai, comme le `DelayStamp` de Messenger.
     *
     * @param array<string, mixed> $config
     */
    private function bindResumePath(array $config): void
    {
        /** @var array<string, mixed> $queue */
        $queue = $config['queue'] ?? [];

        $this->app->singleton(RegistryActivityExecutor::class, fn() => new RegistryActivityExecutor());
        // Le port, pas seulement la classe : `RunActivityJob` demande un
        // `ActivityMessageProcessor`, qui demande un `ActivityExecutor`. Sans cette ligne le
        // conteneur essaie d'instancier une interface, et l'activité échoue au premier essai.
        $this->app->singleton(ActivityExecutor::class, fn($app) => $app->make(RegistryActivityExecutor::class));
        $this->app->singleton(WorkflowDefinitionLoader::class, fn() => new WorkflowDefinitionLoader());

        // Le minuteur suit le backend, comme le transport et le dispatcher de reprise : en
        // mémoire, le drain est dans le processus et n'a personne à réveiller.
        $this->app->singleton(
            WorkflowTimerDispatcher::class,
            ($config['backend'] ?? 'illuminate') === 'illuminate'
                ? fn($app) => new LaravelWorkflowTimerDispatcher(
                    $app->make(QueueFactory::class),
                    $queue['connection'] ?? null,
                    $queue['name'] ?? null,
                )
                : fn() => new NullWorkflowTimerDispatcher(),
        );

        $this->app->singleton(ExecutionRuntime::class, fn($app) => new ExecutionRuntime(
            $app->make(EventStoreInterface::class),
            $app->make(ActivityTransportInterface::class),
            $app->make(RegistryActivityExecutor::class),
            // Les tentatives sont illimitées par défaut, sémantique Temporal ; `distributed: true`
            // parce qu'ici le drain n'est pas dans le processus, c'est `queue:work`.
            0,
            null,
            true,
        ));

        $this->app->singleton(ExecutionEngine::class, fn($app) => new ExecutionEngine(
            $app->make(EventStoreInterface::class),
            $app->make(ExecutionRuntime::class),
        ));

        $this->app->singleton(ActivityMessageProcessor::class, fn($app) => new ActivityMessageProcessor(
            $app->make(EventStoreInterface::class),
            $app->make(ActivityTransportInterface::class),
            $app->make(ActivityExecutor::class),
            $app->make(WorkflowResumeDispatcher::class),
            // Pas de battement de cœur : c'est une capacité de Temporal, et rien ici ne la sert.
            new NullActivityHeartbeatSender(),
            // Tentatives illimitées par défaut, sémantique Temporal. La politique de chaque
            // activité l'emporte quand elle en déclare une.
            0,
        ));

        $this->app->singleton(ResumeWorkflowHandler::class, fn($app) => new ResumeWorkflowHandler(
            $app->make(ExecutionEngine::class),
            $app->make(WorkflowRegistry::class),
            $app->make(WorkflowMetadataStore::class),
            $app->make(WorkflowResumeDispatcher::class),
            $app->make(EventStoreInterface::class),
            $app->make(ChildWorkflowParentLinkStoreInterface::class),
            $app->make(WorkflowTimerDispatcher::class),
            $app->make(WorkflowDefinitionLoader::class),
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

        // Le **nom du driver**, pas la classe de la connexion : `SyncQueue` vit dans
        // `illuminate/queue`, dont Laravel 11+ tire `symfony/process ^7.2` — l'exiger rendrait ce
        // paquet irréconciliable avec la ligne Symfony 6.4 que la matrice du dépôt teste encore.
        // Lire la configuration dit la même chose, sans la dépendance, et sans avoir à résoudre la
        // connexion pour la juger.
        if ($this->driverOf($name) === 'sync') {
            throw new \InvalidArgumentException(\sprintf(
                'Durable: the "%s" queue connection runs jobs inline, so a resume that dispatches '
                . 'another resume recurses in the same process until the stack ends. Use a real '
                . 'queue connection — database, redis, sqs, beanstalkd.',
                $name ?? 'default',
            ));
        }
    }

    private function driverOf(?string $connection): ?string
    {
        if (!$this->app->bound('config')) {
            return null;
        }

        /** @var array<string, mixed> $queueConfig */
        $queueConfig = $this->app['config']['queue'] ?? [];
        $name = $connection ?? ($queueConfig['default'] ?? null);
        /** @var array<string, array<string, mixed>> $connections */
        $connections = $queueConfig['connections'] ?? [];
        $driver = $connections[$name]['driver'] ?? null;

        return \is_string($driver) ? $driver : null;
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
