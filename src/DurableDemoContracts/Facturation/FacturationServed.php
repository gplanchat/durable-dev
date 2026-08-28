<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Demo\Contracts\Facturation;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;

/**
 * Ce que le métier sait répondre tout de suite.
 *
 * Vérifier, c'est appliquer des règles sur des données qu'on a déjà. Encaisser, non — d'où la
 * séparation en deux interfaces.
 */
#[AsNexusService('facturation')]
interface FacturationServed
{
    /**
     * @param int    $montant en centimes, parce qu'un flottant qui traverse un encodage JSON puis un
     *                        décodage n'est plus tout à fait le même nombre
     * @param string $devise  code ISO 4217
     *
     * @return array{acceptee: bool, motif: string|null} `motif` est `null` quand `acceptee` vaut `true`
     */
    #[AsNexusOperation('verifier')]
    public function verifier(string $commande, int $montant, string $devise): array;
}
