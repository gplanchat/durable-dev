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
 * What the application declares it serves in Nexus, carried into the core's registry.
 *
 * This is the counterpart of `NexusHandlerPass` on the Symfony side, and it does the same work by
 * the same path — `NexusContractResolver` to read the contract, `NexusHandlerInvoker` to hold
 * between the handler's signature and what the registry calls. What changes is the source: Symfony
 * reads tags an autoconfiguration has set, Laravel reads `config/durable.php`, because its
 * container has no equivalent — the same reason for which the workflows are declared.
 *
 * **An operation without a body is not a missing operation.** A Nexus contract splits into two
 * interfaces because PHP cannot say "partially implements": what the handler does not serve, a
 * workflow fulfils, and it is `#[FulfilsNexusOperation]` that declares it. What is registered then
 * is the **type** of the workflow and not its class — that is the name the server knows and the
 * journal records.
 */
final class DeclaredNexusOperations
{
    /**
     * @param array<class-string, class-string> $handlers handler => the contract it serves
     * @param list<class-string>                $workflows the declared workflows, where the
     *                                                     operations they fulfil are read
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
                    // The same refusal as on the Symfony side, by the same class: reading a list
                    // from a file does not excuse checking what a compiler pass checks. It falls
                    // here, at registration, and not on the first task — that is the last moment
                    // where somebody is looking.
                    NexusFulfilmentParameterNames::assertMatch(
                        'durable.nexus.handlers',
                        $contract,
                        $method,
                        $operation,
                        $workflowClass,
                    );

                    // The **type**, not the FQCN: that is the name the server knows and that the
                    // journal records.
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

    /** @return array<class-string, array<string, string>> contract => operation => workflow type */
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
