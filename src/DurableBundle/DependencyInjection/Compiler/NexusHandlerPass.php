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
 * Registers on {@see NexusOperationRegistry} the served operations, read **from the contract**.
 *
 * The tag carries nothing but the contract. The service and operation names live in the contract,
 * once, and the caller reads the same object: a typo is a type error, not an operation waiting on
 * a handler whose name will never match.
 *
 * **The refusal happens at startup, and that is this pass's reason to exist.** On the caller's
 * side, a Nexus call on a backend that cannot route fails at the moment of the call — that is
 * where the fault becomes visible. Serving is the reverse: a handler declared on a backend with no
 * route is not a call that fails, it is a service that **never receives anything**, without a line
 * of log. There is no request to fail later.
 *
 * **This pass is not the only guard, and must not be.** {@see NexusOperationRegistry} refuses on
 * its side, in the core: this one only catches Symfony, whereas the Magento module and the
 * Illuminate bridge wire their services differently. The two complement each other — the pass
 * fails **earlier** and names the services at fault, which a registry has no means of doing; the
 * registry catches all the hosts the pass does not see.
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

                // No `is_a($handlerClass, $contract)` here, and that is deliberate. The tag may
                // name the **whole** contract — the one the caller reads — of which the handler
                // only implements the served part; deferred operations have no body. That is
                // precisely why the contract splits into two interfaces, PHP having no way to say
                // "implements partially". Coverage is therefore checked operation by operation,
                // further down — and a class that serves none of them gets caught there.

                $serviceName = $resolver->serviceName($contract);

                foreach ($resolver->operations($contract) as $method => $operation) {
                    if (method_exists($handlerClass, $method)) {
                        // Not the method itself: the registry calls its handler with the whole
                        // payload as argument #1 and expects a `NexusOperationResponse`, when the
                        // handler wrote the signature of its contract. The invoker is what stands
                        // between the two, and it stands in the core so that Magento and the
                        // Illuminate bridge inherit it the day they route.
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

                        // Declared: nothing to call, the worker will start this workflow. It is
                        // handed the **type** and not the FQCN — that is the name the server
                        // knows, and the one the journal records.
                        $registry->addMethodCall('registerFulfilment', [
                            self::named(NexusService::class, $serviceName),
                            self::named(NexusOperationName::class, $operation),
                            (new WorkflowDefinitionLoader())->workflowTypeForClass($workflowClass),
                        ]);

                        continue;
                    }

                    // Neither implemented nor claimed: nobody serves it. The caller would wait on
                    // a result nothing produces, and the server has nothing to say about it.
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
     * A value object passed as a **definition**, and not as an instance.
     *
     * The container of a Symfony project in dev mode is written out as XML on every warmup, by
     * `ContainerBuilderDebugDumpPass`. A method call argument that is an already built object is
     * not serializable: "Unable to dump a service container if a parameter is an object or a
     * resource". The pass therefore compiled perfectly, and the application did not boot — over a
     * Nexus service name.
     *
     * An inline {@see Definition} says the same thing without instantiating: the dumper writes it
     * as an anonymous service, and the object is born at the moment of the call.
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
     * The operations a workflow claims, read from the tag the autoconfiguration added.
     *
     * The declaration lives on the workflow and not on the contract: the contract is read by the
     * caller, which has no business knowing the class that serves it — naming it there would leak
     * the implementation across the very boundary Nexus exists to draw.
     *
     * **Through the tag, and not by sweeping the container.** The version that walked every
     * definition called `class_exists()` on each one, and so loaded every class of the container
     * to read its attributes. It only takes one of them extending a missing parent — a
     * half-installed development bundle, and `Symfony\Bundle\MakerBundle\Maker\AbstractMaker` is
     * the real case that showed it — for the loading to raise a fatal error, in a compiler pass
     * that had nothing to do with it. The tag says exactly what we are looking for, and
     * `DurableBundle::build()` already adds it for that.
     *
     * @return array<string, array<string, class-string>> contract => operation => workflow class
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
