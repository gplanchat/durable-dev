<?php

declare(strict_types=1);

namespace App\Ai\Watch;

/**
 * Le modèle a demandé une veille sur un sujet que l'application ne publie pas.
 *
 * Levée à la frontière et rattrapée dans le code de workflow : l'agent reçoit la liste des sujets
 * connus à la place d'un résultat d'outil, et reprend la main. Une exception plutôt qu'un `null`
 * parce qu'il n'y a rien à faire d'une veille sans sujet — la laisser passer, c'est endormir
 * l'agent pour rien.
 */
final class UnknownWatchSubject extends \InvalidArgumentException
{
    public function __construct(public readonly string $subject)
    {
        parent::__construct(\sprintf('Sujet de veille inconnu : « %s ».', $subject));
    }
}
