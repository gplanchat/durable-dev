<?php

declare(strict_types=1);

namespace App\Ai\Platform;

use Symfony\AI\Platform\Result\RawResultInterface;

/**
 * La réponse du fournisseur telle que le journal la rend.
 *
 * Les convertisseurs des ponts ne sont pas de simples fonctions `tableau → résultat` : celui de
 * Mistral commence par regarder le code HTTP pour distinguer un dépassement de contexte d'une
 * panne. Il lui faut donc un objet réponse, pas seulement des données.
 *
 * Ici il n'y a plus de réponse HTTP — elle a eu lieu dans l'activité, il y a peut-être trois
 * jours. Ce qui est journalisé est **toujours** un succès : {@see \App\Ai\Activity\ModelInvocationActivityHandler}
 * relève sur tout code >= 400, de sorte que la politique de retentative de Durable s'applique à
 * un 429 ou un 503 (DUR011) au lieu de laisser le code workflow s'étrangler dessus. D'où le 200
 * en dur : c'est la seule issue qui atteigne jamais le rejeu.
 */
final readonly class JournaledHttpResult implements RawResultInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private array $data,
    ) {
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getDataStream(): iterable
    {
        // Une activité rend une valeur une fois : un flux de deltas ne se rejoue pas.
        yield from [];
    }

    public function getObject(): object
    {
        return new JournaledHttpResponse($this->data);
    }
}
