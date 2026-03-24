<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Gplanchat\Durable\WorkflowRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Enregistre les workflows tagués durable.workflow dans le registre.
 */
final class WorkflowPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(WorkflowRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(WorkflowRegistry::class);

        foreach ($container->findTaggedServiceIds('durable.workflow') as $id => $tags) {
            $definition = $container->getDefinition($id);
            $class = $definition->getClass() ?? $id;
            if (!\is_string($class) || !str_contains($class, '\\')) {
                continue;
            }
            $registry->addMethodCall('registerClass', [$class]);
        }
    }
}
