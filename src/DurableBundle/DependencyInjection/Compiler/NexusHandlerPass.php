<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\Serving\NexusContractResolver;
use Gplanchat\Durable\Nexus\Serving\NexusFulfilmentParameterNames;
use Gplanchat\Durable\Nexus\Serving\NexusHandlerInvoker;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
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

                $serviceName = $resolver->serviceName($contract);

                foreach ($resolver->operations($contract) as $method => $operation) {
                    if (method_exists($handlerClass, $method)) {
                        // Pas la méthode elle-même : le registre appelle son gestionnaire avec la
                        // charge entière en argument #1 et attend un `NexusOperationResponse`,
                        // quand le gestionnaire a écrit la signature de son contrat. L'invocateur
                        // est ce qui tient entre les deux, et il tient dans le cœur pour que
                        // Magento et le pont Illuminate en héritent le jour où ils routent.
                        $invokerId = 'durable.nexus_invoker.' . hash('xxh128', $serviceId . $contract . $method);
                        $container->register($invokerId, NexusHandlerInvoker::class)
                            ->setArguments([
                                new Reference($serviceId),
                                $contract,
                                $method,
                            ])
                            ->setPublic(false)
                        ;

                        $registry->addMethodCall('register', [
                            self::named(NexusService::class, $serviceName),
                            self::named(NexusOperationName::class, $operation),
                            [new Reference($invokerId), '__invoke'],
                        ]);

                        continue;
                    }

                    $workflowClass = $claimed[$contract][$operation] ?? null;
                    if (null !== $workflowClass) {
                        NexusFulfilmentParameterNames::assertMatch(self::TAG, $contract, $method, $operation, $workflowClass);

                        // Déclarée : rien à appeler, le worker démarrera ce workflow. On lui passe
                        // le **type** et non le FQCN — c'est le nom que le serveur connaît, et
                        // celui que le journal enregistre.
                        $registry->addMethodCall('registerFulfilment', [
                            self::named(NexusService::class, $serviceName),
                            self::named(NexusOperationName::class, $operation),
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
     * Un objet-valeur passé comme **définition**, et non comme instance.
     *
     * Le conteneur d'un projet Symfony en mode dev est écrit en XML à chaque réchauffage, par
     * `ContainerBuilderDebugDumpPass`. Un argument d'appel de méthode qui est un objet déjà
     * construit n'est pas sérialisable : « Unable to dump a service container if a parameter is an
     * object or a resource ». La passe compilait donc parfaitement, et l'application ne démarrait
     * pas — pour un nom de service Nexus.
     *
     * Une {@see Definition} en ligne dit la même chose sans instancier : le dumper l'écrit comme un
     * service anonyme, et l'objet naît au moment de l'appel.
     *
     * @param class-string $class
     */
    private static function named(string $class, string $name): Definition
    {
        return (new Definition($class))
            ->setFactory([$class, 'named'])
            ->setArguments([$name])
        ;
    }

    /**
     * Les opérations qu'un workflow réclame, lues sur la balise que l'autoconfiguration a posée.
     *
     * La déclaration vit sur le workflow et non sur le contrat : le contrat est lu par l'appelant,
     * qui n'a pas à connaître la classe qui le sert — l'y nommer ferait fuir l'implémentation à
     * travers la frontière que Nexus existe pour poser.
     *
     * **Par la balise, et non en balayant le conteneur.** La version qui parcourait toutes les
     * définitions appelait `class_exists()` sur chacune, donc chargeait chaque classe du conteneur
     * pour lire ses attributs. Il suffit qu'une seule d'entre elles étende un parent absent — un
     * bundle de développement à moitié installé, et `Symfony\Bundle\MakerBundle\Maker\AbstractMaker`
     * est le cas réel qui l'a montré — pour que le chargement fasse une erreur fatale, dans une
     * passe de compilation qui n'avait rien à voir. La balise dit exactement ce qu'on cherche, et
     * `DurableBundle::build()` la pose déjà pour ça.
     *
     * @return array<string, array<string, class-string>> contrat => opération => classe du workflow
     */
    private function operationsClaimedByWorkflows(ContainerBuilder $container): array
    {
        $claimed = [];

        foreach ($container->findTaggedServiceIds(self::FULFILMENT_TAG) as $serviceId => $tags) {
            $class = $container->findDefinition($serviceId)->getClass();
            if (null === $class) {
                continue;
            }

            foreach ($tags as $tag) {
                $contract = $tag['contract'] ?? null;
                $operation = $tag['operation'] ?? null;
                if (!\is_string($contract) || !\is_string($operation)) {
                    throw new \LogicException(\sprintf(
                        '%s: service "%s" must declare both a "contract" and an "operation" — #[FulfilsNexusOperation] carries them, and the autoconfiguration copies them onto the tag.',
                        self::FULFILMENT_TAG,
                        $serviceId,
                    ));
                }

                $claimed[$contract][$operation] = $class;
            }
        }

        return $claimed;
    }
}
