<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Gplanchat\Bridge\Temporal\Messenger\TemporalTransportFactory;
use Gplanchat\Durable\WorkflowRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Injecte {@see TemporalTransportFactory} après toutes les extensions / autowiring :
 * le bundle Durable modifiait le service trop tôt ou l'autowiring remettait les args optionnels à null.
 */
final class DurableTemporalTransportFactoryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TemporalTransportFactory::class)) {
            return;
        }

        if (!$container->hasDefinition('durable.temporal.connection')) {
            return;
        }

        $def = $container->getDefinition(TemporalTransportFactory::class);
        $def->setArgument(2, new Reference('durable.temporal.connection'));
        $def->setArgument(3, new Reference(WorkflowRegistry::class));

        if ($container->hasDefinition('durable.temporal.activity_worker')) {
            $def->setArgument(1, new Reference('durable.temporal.activity_worker'));
        }
    }
}
