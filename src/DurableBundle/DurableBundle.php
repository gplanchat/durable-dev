<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle;

use Gplanchat\Durable\Attribute\AsActivityHandler;
use Gplanchat\Durable\Attribute\AsNexusServiceHandler;
use Gplanchat\Durable\Attribute\FulfilsNexusOperation;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\ActivityHandlerPass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\DurableTemporalTransportFactoryPass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\NexusHandlerPass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\RegisterDurableMiddlewarePass;
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
            AsActivityHandler::class,
            static function (ChildDefinition $definition, AsActivityHandler $attribute, \Reflector $_reflector): void {
                $definition->addTag('durable.activity_handler', ['contract' => $attribute->contract]);
            },
        );

        $container->registerAttributeForAutoconfiguration(
            AsNexusServiceHandler::class,
            static function (ChildDefinition $definition, AsNexusServiceHandler $attribute, \Reflector $_reflector): void {
                $definition->addTag(NexusHandlerPass::TAG, ['contract' => $attribute->contract]);
            },
        );

        // A workflow that requests a deferred operation has nothing to register on the registry:
        // the plumbing starts it. The tag is there so that the pass **sees** it — without which it
        // would conclude that the operation is served by nobody and would refuse at startup.
        $container->registerAttributeForAutoconfiguration(
            FulfilsNexusOperation::class,
            static function (ChildDefinition $definition, FulfilsNexusOperation $attribute, \Reflector $_reflector): void {
                $definition->addTag(NexusHandlerPass::FULFILMENT_TAG, [
                    'contract' => $attribute->contract,
                    'operation' => $attribute->operation,
                ]);
            },
        );

        // Before the FrameworkBundle's MessengerPass: enriches messenger.bus.*.middleware.
        $container->addCompilerPass(new RegisterDurableMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);

        $container->addCompilerPass(new WorkflowPass());
        // Priority 50: after AttributeAutoconfigurationPass (100), before the passes at 0 (WorkflowPass, etc.).
        $container->addCompilerPass(new ActivityHandlerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
        // Same priority, same reason: after autoconfiguration by attribute, before the passes at 0.
        $container->addCompilerPass(new NexusHandlerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
        // After all the autowiring passes: injects TemporalActivityWorker into TemporalTransportFactory.
        $container->addCompilerPass(new DurableTemporalTransportFactoryPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }
}
