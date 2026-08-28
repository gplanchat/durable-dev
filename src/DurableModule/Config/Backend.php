<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Magento\Config;

/**
 * Les deux backends que Magento atteint, et le refus des autres.
 *
 * `ALLOWED.magento` vaut `['memory', 'temporal']` dans le sélecteur de la page d'accueil, et ce
 * n'est pas une timidité : `Magento\Framework\App\ResourceConnection` n'est ni une connexion
 * Doctrine DBAL ni celle d'Illuminate, et les deux ponts SQL se lient à ces deux types-là. Faire
 * parler `ResourceConnection` au journal serait une quatrième famille d'adaptateurs — un change à
 * lui seul, avec le sélecteur et OST003 derrière.
 *
 * Le vocabulaire est celui du sélecteur. Le bundle Symfony écrit `in_memory`, mais il nomme là-bas
 * le type d'un **magasin d'événements** ; ici on nomme la **famille de backend**, celle que la page
 * d'accueil rend et que la 6.2 devra retrouver. Deux axes, deux vocabulaires, et les confondre
 * ferait diverger la configuration de sa documentation.
 */
enum Backend: string
{
    case Memory = 'memory';
    case Temporal = 'temporal';

    /**
     * Les ponts SQL de Durable : de vrais backends, refusés ici pour une raison d'hôte.
     */
    private const SQL_BRIDGES = ['dbal', 'illuminate'];

    /**
     * Lit le nom que l'hôte a configuré, ou refuse en le nommant.
     *
     * Absent, on retombe sur `memory` — comme le bundle Symfony retombe sur `in_memory`, et pour
     * une raison de plus : c'est aujourd'hui le seul backend que le module câble réellement. Le
     * jour où la tâche 5 branche Temporal, ce défaut devient un choix qui se paie à la sortie du
     * processus, et il faudra le rouvrir plutôt que d'en hériter.
     */
    public static function fromConfiguredName(?string $name): self
    {
        if ($name === null || $name === '') {
            return self::Memory;
        }

        $backend = self::tryFrom($name);
        if ($backend !== null) {
            return $backend;
        }

        if (\in_array($name, self::SQL_BRIDGES, true)) {
            throw UnsupportedBackendException::sqlBridge($name, self::reachableNames());
        }

        throw UnsupportedBackendException::unknown($name, self::reachableNames());
    }

    /**
     * @return non-empty-list<string>
     */
    private static function reachableNames(): array
    {
        return \array_map(static fn(self $backend): string => $backend->value, self::cases());
    }
}
