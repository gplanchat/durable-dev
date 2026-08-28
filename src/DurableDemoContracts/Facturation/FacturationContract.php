<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Demo\Contracts\Facturation;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;

/**
 * Ce que l'appelant voit du service `facturation`.
 *
 * `encaisser` n'a pas de corps de gestionnaire : un workflow la réclame avec
 * {@see \Gplanchat\Durable\Attribute\FulfilsNexusOperation}, et le serveur livre le résultat de ce
 * workflow à l'appelant. C'est pour elle que le contrat se sépare — PHP ne sait pas dire
 * « implémente partiellement ».
 *
 * ⚠ **Les noms de paramètres déclarés ici sont l'interface, pas une commodité de lecture.** La
 * charge est clée par nom à l'appel et relue par nom dans le workflow qui remplit l'opération. Un
 * paramètre renommé d'un seul côté donne `null` au workflow, sans erreur et sans trace.
 */
#[AsNexusService('facturation')]
interface FacturationContract extends FacturationServed
{
    /**
     * @param int $montant en centimes, comme pour {@see FacturationServed::verifier()}
     *
     * @return array{recu: string, encaisse: int}
     */
    #[AsNexusOperation('encaisser')]
    public function encaisser(string $commande, int $montant, string $devise): array;
}
