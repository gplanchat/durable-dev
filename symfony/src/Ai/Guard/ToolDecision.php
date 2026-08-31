<?php

declare(strict_types=1);

namespace App\Ai\Guard;

/**
 * Trois issues : passer, demander à l'humain, refuser. Le refus n'est pas une exception — il
 * redevient un résultat d'outil rendu au modèle, qui peut alors s'adapter au lieu de planter.
 */
final readonly class ToolDecision
{
    private function __construct(
        public ToolVerdict $verdict,
        public ?string $reason,
    ) {
    }

    public static function allow(): self
    {
        return new self(ToolVerdict::Allow, null);
    }

    public static function ask(string $reason): self
    {
        return new self(ToolVerdict::Ask, $reason);
    }

    public static function deny(string $reason): self
    {
        return new self(ToolVerdict::Deny, $reason);
    }
}
