<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\ActivityExecutor;
use Gplanchat\Durable\Bundle\Activity\PayloadToContractMethodInvoker;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Enregistre sur {@see ActivityExecutor} les activités exposées par les services tagués durable.activity_handler.
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

                    $invokerId = 'durable.activity_invoker.'.hash('xxh128', $serviceId.$contract.$methodName);
                    $container->register($invokerId, PayloadToContractMethodInvoker::class)
                        ->setArguments([
                            new Reference($serviceId),
                            $contract,
                            $methodName,
                        ])
                        ->setPublic(false)
                    ;

                    $executor->addMethodCall('register', [
                        $activityName,
                        [new Reference($invokerId), '__invoke'],
                    ]);
                }
            }
        }
    }

    /**
     * Les contrats d'activité sont des interfaces : {@see class_exists} retourne false pour elles.
     */
    private static function typeExists(string $fqcn): bool
    {
        return interface_exists($fqcn) || class_exists($fqcn);
    }
}
