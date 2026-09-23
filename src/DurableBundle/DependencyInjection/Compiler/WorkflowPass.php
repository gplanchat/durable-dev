<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers the workflows tagged durable.workflow in the registry.
 *
 * The registry loads each class when it is built, at run time. The pass loads it once here too, so
 * a workflow the loader refuses (an `ActivityStub` argument without its contract, for instance)
 * fails the container compilation instead of the first worker that builds the registry.
 */
final class WorkflowPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(WorkflowRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(WorkflowRegistry::class);
        $loader = new WorkflowDefinitionLoader();

        foreach ($container->findTaggedServiceIds('durable.workflow') as $id => $tags) {
            $definition = $container->getDefinition($id);
            $class = $definition->getClass() ?? $id;
            if (!str_contains($class, '\\')) {
                continue;
            }
            $loader->load($class);
            $registry->addMethodCall('registerClass', [$class]);
        }
    }
}
