<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\RegisterDurableMiddlewarePass;
use Gplanchat\Durable\Bundle\DependencyInjection\Loader\Commands;
use Gplanchat\Durable\Bundle\DependencyInjection\Loader\CoreServices;
use Gplanchat\Durable\Bundle\DependencyInjection\Loader\DbalStores;
use Gplanchat\Durable\Bundle\DependencyInjection\Loader\EventStores;
use Gplanchat\Durable\Bundle\DependencyInjection\Loader\MessengerServices;
use Gplanchat\Durable\Bundle\DependencyInjection\Loader\Observability;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
// And not HttpKernel's, which is only a thin subclass of it — `@internal` since
// Symfony 7.1, deprecated in 8.1 — and only adds the leftovers of the annotated class cache.
// This one has existed since 6.4: the swap costs no supported version.
use Symfony\Component\DependencyInjection\Extension\Extension;

final class DurableExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration($this->getConfiguration($configs, $container), $configs);

        $container->setParameter('durable.max_activity_retries', $config['max_activity_retries'] ?? 0);

        $asyncChildMessenger = (bool) ($config['child_workflow']['async_messenger'] ?? false);
        $container->setParameter('durable.child_workflow_async_messenger', $asyncChildMessenger);

        if ($config['profiler']['enabled']) {
            Observability::registerProfiler($container);
        } else {
            Observability::registerNullObserver($container);
        }
        EventStores::registerChildWorkflowParentLinkStore($container);
        CoreServices::registerWorkflowDefinitionLoader($container);
        EventStores::registerEventStore($container, $config);
        MessengerServices::registerActivityTransport($container, $config);
        CoreServices::registerActivityExecutor($container);
        CoreServices::registerRuntime($container);
        MessengerServices::registerWorkflowMessengerServices($container, $config);
        CoreServices::registerParentChildCoordinator($container);
        // The pass that installs the middleware runs well after the extensions; it reads this
        // choice back here rather than rediscovering it.
        $container->setParameter(
            RegisterDurableMiddlewarePass::BUSES_PARAMETER,
            $config['messenger']['buses'] ?? [],
        );

        CoreServices::registerActivityContractResolver($container, $config);
        CoreServices::registerEngine($container);
        CoreServices::registerActivityContractCacheWarmer($container, $config);
        CoreServices::registerWorkflowControlHandlers($container, $config);
        CoreServices::registerWorkflowQueryRunner($container);
        CoreServices::registerWorkflowBackend($container);
        Commands::registerCommands($container, $config);
        EventStores::registerTemporalMirrorInfrastructure($container, $config);
        DbalStores::registerDbalStores($container, $config);
        EventStores::registerInMemoryRunCatalog($container);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        // A synthetic container, an extension test for instance, does not have this parameter;
        // that does not make it production, hence the default to debug.
        return new Configuration(!$container->hasParameter('kernel.debug') || (bool) $container->getParameter('kernel.debug'));
    }

    /**
     * "Native" Temporal: the cluster **is** the journal.
     *
     * A DSN without a journal says something else — the cluster is reachable for whatever needs
     * it, and serving a Nexus operation needs it, but the source of truth stays the one
     * `event_store` names. The two cannot share the same flag: it is the flag that unplugs the
     * activity transport, the resume dispatcher and the dashboard's read aliases.
     *
     * @param array<string, mixed> $config
     *
     * @internal shared with the loaders under Loader/ (#342)
     */
    public static function isTemporalNative(array $config): bool
    {
        $dsn = $config['temporal']['dsn'] ?? null;

        return \is_string($dsn) && '' !== $dsn && false !== ($config['temporal']['journal'] ?? true);
    }

    /**
     * Aliases the observation interface, **without overwriting what the application already
     * declared**.
     *
     * `UPGRADE.md` invites an application that wants to observe its executions in production to
     * implement the contract and alias the interface onto its own service. The definitions in the
     * application's `services.yaml` already exist when the extension loads, since
     * `MergeExtensionConfigurationPass` runs at compilation, after the configuration is loaded, so
     * an unconditional `setAlias()` erased that alias and the escape hatch did not work.
     *
     * @internal shared with the loaders under Loader/ (#342)
     */
    public static function aliasObserver(ContainerBuilder $container, string $service): void
    {
        if ($container->hasAlias(WorkflowExecutionObserverInterface::class)
            || $container->hasDefinition(WorkflowExecutionObserverInterface::class)
        ) {
            return;
        }

        $container->setAlias(WorkflowExecutionObserverInterface::class, $service)
            ->setPublic(true)
        ;
    }

}
