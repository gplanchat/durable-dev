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
}
