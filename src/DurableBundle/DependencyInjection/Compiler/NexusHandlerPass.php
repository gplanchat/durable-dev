<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Attribute\FulfilsNexusOperation;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\Serving\NexusContractResolver;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Enregistre sur {@see NexusOperationRegistry} les opérations servies, lues **depuis le contrat**.
 *
 * La balise ne porte que le contrat. Les noms de service et d'opération vivent dans le contrat, une
 * seule fois, et l'appelant lit le même objet : une faute de frappe est une erreur de type, pas une
 * opération qui attend un gestionnaire dont le nom ne correspondra jamais.
 *
 * **Le refus se fait au démarrage, et c'est la raison d'être de cette passe.** Côté appelant, un
 * appel Nexus sur un backend qui ne sait pas router échoue au moment de l'appel — c'est là que la
 * faute devient visible. Servir est l'inverse : un gestionnaire déclaré sur un backend sans route
 * n'est pas un appel qui échoue, c'est un service qui **ne reçoit jamais rien**, sans une ligne de
 * log. Il n'y a pas de requête à faire échouer plus tard.
 *
 * **Cette passe n'est pas le seul garde, et ne doit pas l'être.** {@see NexusOperationRegistry}
 * refuse de son côté, dans le cœur : celui-ci n'attrape que Symfony, alors que le module Magento et
 * le pont Illuminate montent leurs services autrement. Les deux se complètent — la passe échoue
 * **plus tôt** et nomme les services fautifs, ce qu'un registre n'a pas les moyens de faire ; le
 * registre rattrape tous les hôtes que la passe ne voit pas.
 */
final class NexusHandlerPass implements CompilerPassInterface
{
    public const TAG = 'durable.nexus_handler';

    public const FULFILMENT_TAG = 'durable.nexus_fulfilment';

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
        $resolver = new NexusContractResolver(null);
        $claimed = $this->operationsClaimedByWorkflows($container);

        foreach ($tagged as $serviceId => $tags) {
            foreach ($tags as $tag) {
                $contract = $tag['contract'] ?? null;
                if (!\is_string($contract) || '' === $contract || !interface_exists($contract)) {
                    throw new \LogicException(\sprintf(
                        '%s: service "%s" must declare a "contract" naming the Nexus contract interface it serves.',
                        self::TAG,
                        $serviceId,
                    ));
                }

                $handlerClass = $container->findDefinition($serviceId)->getClass() ?? $serviceId;
                if (!class_exists($handlerClass)) {
                    throw new \LogicException(\sprintf(
                        '%s: handler class for service "%s" is missing or not autoloadable (got %s).',
                        self::TAG,
                        $serviceId,
                        $handlerClass,
                    ));
                }

                // Pas de `is_a($handlerClass, $contract)` ici, et c'est délibéré. La balise peut
                // nommer le contrat **complet** — celui que l'appelant lit —, dont le gestionnaire
                // n'implémente que la part servie ; les opérations différées n'ont pas de corps.
                // C'est précisément pourquoi le contrat se sépare en deux interfaces, PHP ne sachant
                // pas dire « implémente partiellement ». La couverture se vérifie donc opération par
                // opération, plus bas — et une classe qui n'en sert aucune s'y fait prendre.

                $service = NexusService::named($resolver->serviceName($contract));

                foreach ($resolver->operations($contract) as $method => $operation) {
                    if (method_exists($handlerClass, $method)) {
                        $registry->addMethodCall('register', [
                            $service,
                            NexusOperationName::named($operation),
                            [new Reference($serviceId), $method],
                        ]);

                        continue;
                    }

                    $workflowClass = $claimed[$contract][$operation] ?? null;
                    if (null !== $workflowClass) {
                        // Déclarée : rien à appeler, le worker démarrera ce workflow. On lui passe
                        // le **type** et non le FQCN — c'est le nom que le serveur connaît, et
                        // celui que le journal enregistre.
                        $registry->addMethodCall('registerFulfilment', [
                            $service,
                            NexusOperationName::named($operation),
                            (new WorkflowDefinitionLoader())->workflowTypeForClass($workflowClass),
                        ]);

                        continue;
                    }

                    // Ni implémentée, ni réclamée : personne ne la sert. L'appelant attendrait un
                    // résultat que rien ne produit, et le serveur n'a rien à en dire.
                    throw new \LogicException(\sprintf(
                        '%s: operation "%s" of contract %s is served by nobody — handler "%s" does not implement %s() and no workflow claims it with #[FulfilsNexusOperation]. A caller would wait on a result nothing produces.',
                        self::TAG,
                        $operation,
                        $contract,
                        $handlerClass,
                        $method,
                    ));
                }

            }
        }
    }

    /**
     * Les opérations qu'un workflow réclame, lues sur les workflows du conteneur.
     *
     * La déclaration vit sur le workflow et non sur le contrat : le contrat est lu par l'appelant,
     * qui n'a pas à connaître la classe qui le sert — l'y nommer ferait fuir l'implémentation à
     * travers la frontière que Nexus existe pour poser.
     *
     * @return array<string, array<string, class-string>> contrat => opération => classe du workflow
     */
    private function operationsClaimedByWorkflows(ContainerBuilder $container): array
    {
        $claimed = [];

        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();
            if (null === $class || !class_exists($class)) {
                continue;
            }

            foreach ((new \ReflectionClass($class))->getAttributes(FulfilsNexusOperation::class) as $attribute) {
                $fulfilment = $attribute->newInstance();
                $claimed[$fulfilment->contract][$fulfilment->operation] = $class;
            }
        }

        return $claimed;
    }
}
