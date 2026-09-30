<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Nexus;

use Gplanchat\Durable\Nexus\Serving\NexusHandlerDeclarations;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Illuminate\Contracts\Container\Container;

/**
 * What the application declares it serves in Nexus, carried into the core's registry.
 *
 * The work is the core's ({@see NexusHandlerDeclarations}), shared with Magento (#668); this class
 * hands it Laravel's container. It is the counterpart of `NexusHandlerPass` on the Symfony side, by
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
final readonly class DeclaredNexusOperations
{
    /**
     * @param array<array-key, class-string> $handlers  handler => the contract it serves, or a
     *                                                  handler alone, whose contract its
     *                                                  #[AsNexusServiceHandler] names
     * @param list<class-string>             $workflows the declared workflows, where the
     *                                                  operations they fulfil are read
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $handlers = [],
        private readonly array $workflows = [],
    ) {}

    public function registerInto(NexusOperationRegistry $registry): void
    {
        (new NexusHandlerDeclarations(
            $this->handlers,
            $this->workflows,
            fn(string $handlerClass): object => $this->container->make($handlerClass),
            'durable.nexus.handlers',
            'The key of durable.nexus.handlers is the handler class, the value is the contract interface it serves.',
            'durable.workflows',
        ))->registerInto($registry);
    }
}
