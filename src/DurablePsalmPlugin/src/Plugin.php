<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Psalm;

use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\PluginRegistrationSocket;

/**
 * Enregistre l'analyse des appels magiques sur {@see \Gplanchat\Durable\Activity\ActivityStub}
 * (voir {@see ActivityStubPsalmHandlers}). PHPStan reste l'outil de référence pour les génériques
 * les plus avancés (ADR012).
 *
 * @psalm-suppress UnusedClass Loaded by Psalm via composer extra.psalm.pluginClass
 */
final class Plugin implements PluginEntryPointInterface
{
    #[\Override]
    public function __invoke(PluginRegistrationSocket $registration, ?\SimpleXMLElement $config = null): void
    {
        class_exists(ActivityStubPsalmHandlers::class, true);
        $registration->registerHooksFromClass(ActivityStubPsalmHandlers::class);
    }
}
