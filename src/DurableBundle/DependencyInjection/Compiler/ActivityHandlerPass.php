<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Activity\PayloadToContractMethodInvoker;
use Gplanchat\Durable\ActivityExecutor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers on {@see ActivityExecutor} the activities exposed by the services tagged durable.activity_handler.
 *
 * Through a **service locator**, not through an array of callables. Passing
 * `[new Reference($invoker), '__invoke']` would force the container to resolve every reference to
 * build the argument: it would then instantiate every handler in the application, and their
 * connections, HTTP clients and other dependencies, in order to call one. On a worker that handles
 * one activity per message, that is paid on every message.
 */
final class ActivityHandlerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $executorId = ActivityExecutor::class;
        $tagged = $container->findTaggedServiceIds('durable.activity_handler');
        if (!$container->has($executorId)) {
            return;
        }

        if ([] === $tagged) {
            return;
        }

        while ($container->hasAlias($executorId)) {
            $executorId = (string) $container->getAlias($executorId);
        }

        if (!$container->hasDefinition($executorId)) {
            return;
        }

        $executor = $container->findDefinition($executorId);
        $resolver = new ActivityContractResolver(null);

        /** @var array<string, Reference> $handlerRefs */
        $handlerRefs = [];

        foreach ($tagged as $serviceId => $tags) {
            foreach ($tags as $tag) {
                $contract = $tag['contract'] ?? null;
                if (!\is_string($contract) || '' === $contract) {
                    continue;
                }

                if (!self::typeExists($contract)) {
                    throw new \LogicException(\sprintf('durable.activity_handler: contract "%s" is not a loadable interface or class (service "%s").', $contract, $serviceId));
                }

                $handlerDef = $container->findDefinition($serviceId);
                $handlerClass = $handlerDef->getClass() ?? $serviceId;
                if (!class_exists($handlerClass)) {
                    throw new \LogicException(\sprintf('durable.activity_handler: handler class for service "%s" is missing or not autoloadable (got %s).', $serviceId, $handlerClass));
                }

                $methodToActivity = $resolver->resolveActivityMethods($contract);
                foreach ($methodToActivity as $methodName => $activityName) {
                    if (!method_exists($handlerClass, $methodName)) {
                        throw new \LogicException(\sprintf('Handler "%s" must implement %s::%s() for durable.activity_handler (contract %s).', $handlerClass, $contract, $methodName, $contract));
                    }

                    $invokerId = 'durable.activity_invoker.' . hash('xxh128', $serviceId . $contract . $methodName);
                    $container->register($invokerId, PayloadToContractMethodInvoker::class)
                        ->setArguments([
                            new Reference($serviceId),
                            $contract,
                            $methodName,
                        ])
                        ->setPublic(false)
                    ;

                    // A reference in the locator, not a callable built at
                    // compilation: building `[new Reference(...), '__invoke']` would force the
                    // container to instantiate **every** invoker, so every handler and its
                    // dependencies, in order to call one.
                    $handlerRefs[$activityName] = new Reference($invokerId);
                }
            }
        }

        if ([] === $handlerRefs) {
            return;
        }

        // The locator builds only what it is asked for, and `ServiceLocatorTagPass` deduplicates it
        // across passes: it is the upstream mechanism for "many candidates, one called", the one
        // `MessengerPass` uses for message handlers.
        $executor->setArgument('$lazyHandlers', ServiceLocatorTagPass::register($container, $handlerRefs));
    }

    /**
     * Activity contracts are interfaces: {@see class_exists} returns false for them.
     */
    private static function typeExists(string $fqcn): bool
    {
        return interface_exists($fqcn) || class_exists($fqcn);
    }
}
