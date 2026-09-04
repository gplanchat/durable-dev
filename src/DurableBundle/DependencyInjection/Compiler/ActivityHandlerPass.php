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
 * Enregistre sur {@see ActivityExecutor} les activités exposées par les services tagués durable.activity_handler.
 *
 * Par un **localisateur de services**, et non par un tableau de callables. Poser
 * `[new Reference($invoker), '__invoke']` obligerait le conteneur à résoudre chaque référence pour
 * bâtir l'argument : il instancierait alors tous les gestionnaires de l'application — et leurs
 * connexions, clients HTTP et autres dépendances — pour en appeler un seul. Sur un worker qui
 * traite une activité par message, c'est payé à chaque message.
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

                    // Une référence dans le localisateur, pas un callable construit à la
                    // compilation : bâtir `[new Reference(...), '__invoke']` obligerait le
                    // conteneur à instancier **chaque** invoker — donc chaque gestionnaire et ses
                    // dépendances — pour en appeler un seul.
                    $handlerRefs[$activityName] = new Reference($invokerId);
                }
            }
        }

        if ([] === $handlerRefs) {
            return;
        }

        // Le localisateur ne construit que ce qu'on lui demande, et `ServiceLocatorTagPass` le
        // déduplique entre passes : c'est le mécanisme amont pour « beaucoup de candidats, un seul
        // appelé », celui qu'emploie `MessengerPass` pour les gestionnaires de messages.
        $executor->setArgument('$lazyHandlers', ServiceLocatorTagPass::register($container, $handlerRefs));
    }

    /**
     * Les contrats d'activité sont des interfaces : {@see class_exists} retourne false pour elles.
     */
    private static function typeExists(string $fqcn): bool
    {
        return interface_exists($fqcn) || class_exists($fqcn);
    }
}
