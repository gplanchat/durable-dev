<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Magento\Queue;

/**
 * Le message porte quelque chose que la file de cet hôte ne sait pas rendre à l'identique.
 *
 * Il lève plutôt que d'encoder au mieux, et la raison est mesurée : donné un objet de transport de
 * Durable, l'encodeur de Magento rend `[]` **sans lever** — le publieur réussit, la charge est
 * vide, et l'identifiant d'exécution a disparu avant que le consommateur échoue, dans un autre
 * processus. Un codec qui laisse tomber un champ rejoue cette panne un cran plus bas.
 */
final class UncarryableMessageException extends \RuntimeException
{
    /**
     * @param non-empty-list<string> $fields
     */
    public static function fields(array $fields): self
    {
        return new self(\sprintf(
            'This activity message carries %s, which the Magento queue codec cannot round-trip yet. It refuses rather than dropping it silently: a message that loses its retry policy comes back looking healthy and behaves differently.',
            \implode(' and ', $fields),
        ));
    }
}
