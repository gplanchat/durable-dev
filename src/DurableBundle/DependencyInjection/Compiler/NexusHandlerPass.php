<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Enregistre sur {@see NexusOperationRegistry} les opérations servies par les services tagués
 * `durable.nexus_handler`.
 *
 * **Le refus se fait ici, au démarrage, et c'est le point de cette passe.** Côté appelant, un appel
 * Nexus sur un backend qui ne sait pas router échoue au moment de l'appel — c'est là que la faute
 * devient visible. Servir est l'inverse : un gestionnaire déclaré sur un backend sans route n'est
 * pas un appel qui échoue, c'est un service qui **ne reçoit jamais rien**, sans une ligne de log.
 * Il n'y a pas de requête à faire échouer plus tard. Alors on échoue à la compilation du conteneur,
 * en nommant le backend, plutôt que de laisser une application démarrer sur un silence.
 *
 * **Cette passe n'est pas le seul garde, et ne doit pas l'être.** {@see NexusOperationRegistry}
 * refuse de son côté, dans le cœur : celui-ci n'attrape que Symfony, alors que le module Magento et
 * le pont Illuminate montent leurs services autrement. Les deux se complètent plutôt qu'ils ne se
 * doublent — la passe échoue **plus tôt** et nomme les services fautifs, ce qu'un registre n'a pas
 * les moyens de faire ; le registre rattrape tous les hôtes que la passe ne voit pas.
 */
final class NexusHandlerPass implements CompilerPassInterface
{
    public const TAG = 'durable.nexus_handler';

    public function process(ContainerBuilder $container): void
    {
        $tagged = $container->findTaggedServiceIds(self::TAG);
        if ([] === $tagged) {
            return;
        }

        if (!$container->hasDefinition('durable.temporal.nexus_registry')) {
            throw new \LogicException(\sprintf(
                '%s: a Nexus handler is declared, but this backend cannot route Nexus operations. Nexus needs the Temporal backend — set durable.temporal.dsn. Declared by: %s.',
                self::TAG,
                implode(', ', array_keys($tagged)),
            ));
        }

        $registry = $container->findDefinition('durable.temporal.nexus_registry');

        foreach ($tagged as $serviceId => $tags) {
            foreach ($tags as $tag) {
                $service = $tag['service'] ?? null;
                $operation = $tag['operation'] ?? null;

                if (!\is_string($service) || '' === $service || !\is_string($operation) || '' === $operation) {
                    throw new \LogicException(\sprintf(
                        '%s: service "%s" must declare both "service" and "operation" on the tag — they are what an incoming task is routed by, and nothing else identifies it.',
                        self::TAG,
                        $serviceId,
                    ));
                }

                $method = \is_string($tag['method'] ?? null) && '' !== $tag['method'] ? $tag['method'] : '__invoke';

                $handlerClass = $container->findDefinition($serviceId)->getClass() ?? $serviceId;
                if (!class_exists($handlerClass) || !method_exists($handlerClass, $method)) {
                    throw new \LogicException(\sprintf(
                        '%s: handler "%s" must implement %s() to serve %s/%s.',
                        self::TAG,
                        $handlerClass,
                        $method,
                        $service,
                        $operation,
                    ));
                }

                // Les deux noms sont validés ici plutôt qu'à la première tâche : une faute de frappe
                // dans un nom de service donne un gestionnaire que rien n'atteint jamais.
                NexusService::named($service);
                NexusOperationName::named($operation);

                $registry->addMethodCall('register', [
                    NexusService::named($service),
                    NexusOperationName::named($operation),
                    [new Reference($serviceId), $method],
                ]);
            }
        }
    }
}
