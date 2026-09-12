<?php

declare(strict_types=1);

namespace App\Ai\Guard;

/**
 * Le mode décide de ce qui passe sans demander. Il est **état de workflow**, changé par signal :
 * passer en `auto` au milieu d'une conversation est journalisé, donc rejoué à l'identique.
 */
enum AgentMode: string
{
    /** Tout passe. Personne ne regarde. */
    case Auto = 'auto';

    /** Les écritures passent, les effets externes demandent. */
    case Edition = 'edition';

    /** Seules les lectures passent. */
    case Standard = 'standard';

    /**
     * La règle du mode, énoncée une fois — c'est ici qu'elle appartient, pas dans les comparaisons
     * d'une garde.
     *
     * | | lecture | écriture | externe |
     * |---|---|---|---|
     * | `auto` | passe | passe | passe |
     * | `edition` | passe | passe | demande |
     * | `standard` | passe | demande | demande |
     */
    public function requiresApprovalFor(ToolEffect $effect): bool
    {
        return match ($this) {
            self::Auto => false,
            self::Edition => $effect->isIrreversible(),
            self::Standard => !$effect->isHarmless(),
        };
    }

    /**
     * Combien ce mode laisse passer. Les trois cas sont un ordre total, et il vit ici pour la même
     * raison que {@see requiresApprovalFor()} : c'est une propriété du mode, pas une comparaison
     * que chaque appelant refait à sa façon.
     */
    private function permissiveness(): int
    {
        return match ($this) {
            self::Standard => 0,
            self::Edition => 1,
            self::Auto => 2,
        };
    }

    /**
     * Le plus strict de plusieurs modes — **l'autorité ne grandit pas par délégation**.
     *
     * Un agent délégué prend le plus strict de sa chaîne. Sans ça, déléguer serait le chemin
     * d'échappement de la garde : un agent en `standard` ne peut pas envoyer de courriel, mais il
     * confierait la tâche à un sous-agent en `auto` qui l'enverrait. La garde ne serait pas
     * contournée par une faille, elle serait devenue décorative.
     */
    public static function strictest(self ...$modes): self
    {
        $strict = self::Auto;
        foreach ($modes as $mode) {
            if ($mode->permissiveness() < $strict->permissiveness()) {
                $strict = $mode;
            }
        }

        return $strict;
    }

    /**
     * Ce mode desserre-t-il le plafond ? C'est la question que pose un `set_mode` reçu en cours de
     * route — le plafond vaut à l'entrée **et** après, sinon un sous-agent le lèverait d'un signal.
     */
    public function loosens(self $ceiling): bool
    {
        return $this->permissiveness() > $ceiling->permissiveness();
    }
}
