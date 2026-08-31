<?php

declare(strict_types=1);

namespace App\Ai\Activity;

use Gplanchat\Durable\Attribute\AsActivityHandler;
use Psr\Container\ContainerInterface;

/**
 * Route un appel d'outil vers son implémentation. **Non couvert par le test du spike** : celui-ci
 * enregistre directement un handler pour `ai_tool_call` dans l'environnement de test.
 *
 * Route un appel d'outil vers son implémentation. Chaque appel est une activité : journalisée,
 * retentée selon ses `ActivityOptions`, et jamais ré-exécutée au rejeu.
 */
#[AsActivityHandler(contract: AgentToolActivityInterface::class)]
final class AgentToolActivityHandler implements AgentToolActivityInterface
{
    public function __construct(
        private readonly ContainerInterface $tools,
    ) {
    }

    public function callTool(string $name, array $arguments): string
    {
        if (!$this->tools->has($name)) {
            // ponytail: l'échec remonte et tue l'appel agent. Le renvoyer au modèle comme
            // résultat d'outil est l'autre politique possible — c'est DUR011 qui doit trancher.
            throw new \InvalidArgumentException(\sprintf('Outil "%s" inconnu.', $name));
        }

        return (string) $this->tools->get($name)($arguments);
    }
}
