<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle;

use Gplanchat\Durable\Bundle\Attribute\AsDurableActivity;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\ActivityHandlerPass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\DurableTemporalTransportFactoryPass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\RegisterWorkflowDispatchProfilerMiddlewarePass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\WorkflowPass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class DurableBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            AsDurableActivity::class,
            static function (ChildDefinition $definition, AsDurableActivity $attribute, \Reflector $_reflector): void {
                $definition->addTag('durable.activity_handler', ['contract' => $attribute->contract]);
            }
        );

        // Avant MessengerPass du FrameworkBundle : enrichit messenger.bus.*.middleware.
        $container->addCompilerPass(new RegisterWorkflowDispatchProfilerMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);

        $container->addCompilerPass(new WorkflowPass());
        // Priorité 50 : après AttributeAutoconfigurationPass (100), avant les passes à 0 (WorkflowPass, etc.).
        $container->addCompilerPass(new ActivityHandlerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
        // Après tous les passes d'autowiring : injecte TemporalActivityWorker dans TemporalTransportFactory.
        $container->addCompilerPass(new DurableTemporalTransportFactoryPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }
}
