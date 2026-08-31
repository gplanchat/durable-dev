<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Demo\Contracts\Livraison;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;

/**
 * Ce que l'appelant voit du service `livraison`, servi par la maquette Laravel.
 *
 * Même forme que les deux autres contrats, et pour la même raison : `expedier` n'a pas de corps de
 * gestionnaire — un workflow la réclame avec
 * {@see \Gplanchat\Durable\Attribute\FulfilsNexusOperation}. Ce qui change ici est **l'hôte** :
 * c'est la première fois que la moitié servante d'un contrat est câblée ailleurs que dans le
 * conteneur de Symfony, par `config/durable.php` et non par une passe de compilation.
 *
 * ⚠ **Les noms de paramètres déclarés ici sont l'interface.** La charge est clée par nom à l'appel
 * et relue par nom dans le workflow qui remplit l'opération. Les deux hôtes servants le vérifient
 * désormais au même moment — à l'enregistrement — par la même classe du cœur,
 * {@see \Gplanchat\Durable\Nexus\Serving\NexusFulfilmentParameterNames} : Symfony depuis sa
 * passe de compilation, Laravel depuis `config/durable.php`.
 */
#[AsNexusService('livraison')]
interface LivraisonContract extends LivraisonServed
{
    /**
     * @param string $commande identifiant de commande
     * @param string $creneau  celui que {@see LivraisonServed::planifier()} a rendu
     *
     * @return array{expediee: bool, suivi: string}
     */
    #[AsNexusOperation('expedier')]
    public function expedier(string $commande, string $creneau): array;
}
