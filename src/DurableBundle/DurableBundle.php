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
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\RequireLockFactoryPass;
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

        // Un workflow qui réclame une opération différée n'a rien à enregistrer sur le registre :
        // la plomberie le démarre. La balise sert à ce que la passe le **voie** — sans quoi elle
        // conclurait que l'opération n'est servie par personne et refuserait au démarrage.
        $container->registerAttributeForAutoconfiguration(
            FulfilsNexusOperation::class,
            static function (ChildDefinition $definition, FulfilsNexusOperation $attribute, \Reflector $_reflector): void {
                $definition->addTag(NexusHandlerPass::FULFILMENT_TAG, [
                    'contract' => $attribute->contract,
                    'operation' => $attribute->operation,
                ]);
            },
        );

        // Avant MessengerPass du FrameworkBundle : enrichit messenger.bus.*.middleware.
        $container->addCompilerPass(new RegisterDurableMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);

        $container->addCompilerPass(new WorkflowPass());
        // Priorité 50 : après AttributeAutoconfigurationPass (100), avant les passes à 0 (WorkflowPass, etc.).
        $container->addCompilerPass(new ActivityHandlerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
        // Même priorité, même raison : après l'autoconfiguration par attribut, avant les passes à 0.
        $container->addCompilerPass(new NexusHandlerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
        // Après l'enregistrement des services DBAL, avant que le conteneur ne se plaigne d'un
        // service inexistant : le message de la passe dit quoi configurer, pas seulement quoi manque.
        $container->addCompilerPass(new RequireLockFactoryPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 20);

        // Après tous les passes d'autowiring : injecte TemporalActivityWorker dans TemporalTransportFactory.
        $container->addCompilerPass(new DurableTemporalTransportFactoryPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }
}
