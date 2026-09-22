<?php

declare(strict_types=1);

namespace App\Ai\Chat;

/**
 * Les appels d'outils auxquels le journal a déjà répondu.
 *
 * Quatre événements différents règlent un appel — l'activité qui l'exécute, la décision de la
 * garde, la réponse à une question, l'alerte qui lève une veille — mais la projection n'en fait
 * qu'un usage : décider si le dernier tour attend encore quelque chose. C'était donc quatre
 * ensembles `array<string, true>` parcourus par une seule condition à quatre `isset()`, où ajouter
 * une cinquième façon de régler un appel voulait dire ne pas oublier de l'ajouter aux deux endroits.
 *
 * Un ensemble, une question : {@see has()}.
 */
final class SettledCalls
{
    /** @var array<string, true> */
    private array $ids = [];

    /**
     * L'identifiant vide entre comme un autre, et c'est délibéré : c'est ce que faisaient les
     * quatre tableaux. Un signal malformé règle alors l'appel sans identifiant — deux cas
     * dégénérés qui s'annulent. Le refuser ici serait une correction, pas une reformulation, et
     * elle changerait le statut affiché.
     */
    public function settle(string $callId): void
    {
        $this->ids[$callId] = true;
    }

    public function has(string $callId): bool
    {
        return isset($this->ids[$callId]);
    }
}
