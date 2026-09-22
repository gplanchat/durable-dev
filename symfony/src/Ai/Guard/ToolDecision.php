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
        private ToolVerdict $verdict,
        public ?string $reason,
    ) {
    }

    /**
     * L'appel part sans que personne n'ait à trancher.
     */
    public function isAllowed(): bool
    {
        return ToolVerdict::Allow === $this->verdict;
    }

    /**
     * L'appel suspend l'exécution jusqu'à une décision humaine — ou jusqu'à l'échéance.
     */
    public function needsApproval(): bool
    {
        return ToolVerdict::Ask === $this->verdict;
    }

    /**
     * L'appel ne partira pas, quel que soit le mode : la politique l'interdit.
     */
    public function isDenied(): bool
    {
        return ToolVerdict::Deny === $this->verdict;
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
