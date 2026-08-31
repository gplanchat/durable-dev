<?php

declare(strict_types=1);

namespace App\Ai\Guard;

use Symfony\AI\Platform\Result\ToolCall;

/**
 * La garde par défaut : une liste de refus qui l'emporte toujours, puis le croisement mode × effet.
 *
 * | | lecture | écriture | externe |
 * |---|---|---|---|
 * | `auto` | passe | passe | passe |
 * | `edition` | passe | passe | demande |
 * | `standard` | passe | demande | demande |
 *
 * ponytail: pas de moteur de règles. Un outil inconnu est traité comme `external` — le défaut
 * prudent. Des conditions sur les arguments (« ce chemin, pas cet autre ») justifieraient une
 * seconde implémentation de {@see ToolGuardInterface}, pas une option de plus ici.
 */
final class ModeToolGuard implements ToolGuardInterface
{
    /**
     * @param array<string, ToolEffect> $effects map nom d'outil → effet déclaré
     * @param list<string>              $denied  outils refusés quel que soit le mode
     */
    public function __construct(
        private readonly array $effects = [],
        private readonly array $denied = [],
    ) {
    }

    public function decide(ToolCall $toolCall, AgentMode $mode): ToolDecision
    {
        $name = $toolCall->getName();

        if (\in_array($name, $this->denied, true)) {
            return ToolDecision::deny(\sprintf('L\'outil « %s » est interdit par la politique de l\'agent.', $name));
        }

        $effect = $this->effects[$name] ?? ToolEffect::External;

        $needsApproval = match ($mode) {
            AgentMode::Auto => false,
            AgentMode::Edition => ToolEffect::External === $effect,
            AgentMode::Standard => ToolEffect::Read !== $effect,
        };

        return $needsApproval
            ? ToolDecision::ask(\sprintf('« %s » (%s) demande une validation en mode %s.', $name, $effect->value, $mode->value))
            : ToolDecision::allow();
    }
}
