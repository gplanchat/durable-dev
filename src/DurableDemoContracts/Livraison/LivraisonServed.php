<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Demo\Contracts\Livraison;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;

/**
 * Ce que la logistique sait répondre tout de suite.
 *
 * Choisir un créneau et un transporteur est un calcul sur des données qu'on a déjà : cela tient
 * dans les ~9 s d'une tâche Nexus. Sortir la marchandise, non — c'est
 * {@see LivraisonContract::expedier()}, et c'est un workflow qui la remplit.
 */
#[AsNexusService('livraison')]
interface LivraisonServed
{
    /**
     * @param string             $commande identifiant de commande, pour que planifier deux fois la
     *                                     même commande rende le même créneau
     * @param array<string, int> $lignes   référence => quantité, ce qu'il y a à faire porter
     *
     * @return array{planifiee: bool, creneau: string, transporteur: string, motif: string|null}
     *                                     `motif` n'a de sens que si `planifiee` vaut `false`
     */
    #[AsNexusOperation('planifier')]
    public function planifier(string $commande, array $lignes): array;
}
