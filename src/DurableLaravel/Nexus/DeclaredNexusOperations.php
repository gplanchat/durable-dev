<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Nexus;

use Gplanchat\Durable\Attribute\FulfilsNexusOperation;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\Serving\NexusContractResolver;
use Gplanchat\Durable\Nexus\Serving\NexusFulfilmentParameterNames;
use Gplanchat\Durable\Nexus\Serving\NexusHandlerInvoker;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Illuminate\Contracts\Container\Container;

/**
 * Ce que l'application déclare servir en Nexus, porté dans le registre du cœur.
 *
 * C'est le pendant de `NexusHandlerPass` côté Symfony, et il fait le même travail par le même
 * chemin — `NexusContractResolver` pour lire le contrat, `NexusHandlerInvoker` pour tenir entre la
 * signature du gestionnaire et ce que le registre appelle. Ce qui change est la source : Symfony
 * lit des balises qu'une autoconfiguration a posées, Laravel lit `config/durable.php`, parce que
 * son conteneur n'a pas d'équivalent — la même raison qui fait déclarer les workflows.
 *
 * **Une opération sans corps n'est pas une opération manquante.** Un contrat Nexus se sépare en
 * deux interfaces parce que PHP ne sait pas dire « implémente partiellement » : ce que le
 * gestionnaire ne sert pas, un workflow le remplit, et c'est `#[FulfilsNexusOperation]` qui le
 * déclare. On enregistre alors le **type** du workflow et non sa classe — c'est le nom que le
 * serveur connaît et que le journal enregistre.
 */
final class DeclaredNexusOperations
{
    /**
     * @param array<class-string, class-string> $handlers gestionnaire => contrat qu'il sert
     * @param list<class-string>                $workflows les workflows déclarés, où se lisent les
     *                                                     opérations qu'ils remplissent
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $handlers = [],
        private readonly array $workflows = [],
    ) {}

    public function registerInto(NexusOperationRegistry $registry): void
    {
        $resolver = new NexusContractResolver(null);
        $claimed = $this->operationsClaimedByWorkflows();

        foreach ($this->handlers as $handlerClass => $contract) {
            if (!interface_exists($contract)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Durable: "%s" is declared as the Nexus contract of %s, but no such interface exists. '
                    . 'The key of durable.nexus.handlers is the handler class, the value is the contract '
                    . 'interface it serves.',
                    $contract,
                    $handlerClass,
                ));
            }

            $service = NexusService::named($resolver->serviceName($contract));
            $served = 0;

            foreach ($resolver->operations($contract) as $method => $operation) {
                $name = NexusOperationName::named($operation);

                if (method_exists($handlerClass, $method)) {
                    $invoker = new NexusHandlerInvoker($this->container->make($handlerClass), $contract, $method);
                    $registry->register($service, $name, $invoker(...));
                    ++$served;

                    continue;
                }

                $workflowClass = $claimed[$contract][$operation] ?? null;
                if (null !== $workflowClass) {
                    // Le même refus que côté Symfony, par la même classe : lire une liste dans un
                    // fichier ne dispense pas de vérifier ce qu'une passe de compilation vérifie.
                    // Il tombe ici, à l'enregistrement, et pas à la première tâche — c'est le
                    // dernier moment où quelqu'un regarde.
                    NexusFulfilmentParameterNames::assertMatch(
                        'durable.nexus.handlers',
                        $contract,
                        $method,
                        $operation,
                        $workflowClass,
                    );

                    // Le **type**, pas le FQCN : c'est le nom que le serveur connaît et que le
                    // journal enregistre.
                    $registry->registerFulfilment(
                        $service,
                        $name,
                        (new WorkflowDefinitionLoader())->workflowTypeForClass($workflowClass),
                    );
                    ++$served;
                }
            }

            if (0 === $served) {
                throw new \InvalidArgumentException(\sprintf(
                    'Durable: %s serves none of the operations of %s — neither a method nor a workflow '
                    . 'carrying #[FulfilsNexusOperation] answers for any of them. A handler that serves '
                    . 'nothing is a declaration nobody will notice is dead.',
                    $handlerClass,
                    $contract,
                ));
            }
        }
    }

    /** @return array<class-string, array<string, string>> contrat => opération => type de workflow */
    private function operationsClaimedByWorkflows(): array
    {
        $claimed = [];

        foreach ($this->workflows as $workflowClass) {
            if (!class_exists($workflowClass)) {
                continue;
            }

            foreach ((new \ReflectionClass($workflowClass))->getAttributes(FulfilsNexusOperation::class) as $attribute) {
                $fulfils = $attribute->newInstance();
                $claimed[$fulfils->contract][$fulfils->operation] = $workflowClass;
            }
        }

        return $claimed;
    }
}
