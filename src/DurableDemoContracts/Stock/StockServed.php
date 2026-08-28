<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Demo\Contracts\Stock;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;

/**
 * Ce que la boutique sait répondre tout de suite.
 *
 * C'est l'interface qu'un gestionnaire **implémente** : réserver du stock est une lecture et une
 * écriture dans le modèle de la boutique, pas une attente. Elle tient dans les ~9 s dont dispose
 * une tâche Nexus avant redélivrance.
 */
#[AsNexusService('stock')]
interface StockServed
{
    /**
     * @param string             $commande  identifiant de la commande, pour que la réservation soit
     *                                      idempotente : la même commande deux fois ne réserve pas deux fois
     * @param array<string, int> $lignes    référence => quantité demandée
     *
     * @return array{reserve: bool, manquants: array<string, int>} `manquants` est vide quand
     *                                      `reserve` vaut `true` — l'appelant n'a donc qu'un champ à lire
     *                                      pour décider, et le second pour expliquer
     */
    #[AsNexusOperation('reserver')]
    public function reserver(string $commande, array $lignes): array;
}
