<?php

declare(strict_types=1);

namespace App\Ai\Guard;

/**
 * Ce que fait un outil, déclaré avec son schéma. C'est cette classification — et pas le nom de
 * l'outil — que le mode consulte.
 */
enum ToolEffect: string
{
    /** N'écrit rien : peut être rejoué sans conséquence. */
    case Read = 'read';

    /** Écrit dans le périmètre de l'application. */
    case Write = 'write';

    /** Sort du périmètre : mail, paiement, appel à un tiers. Ce qui ne se compense pas d'un clic. */
    case External = 'external';
}
