<?php

declare(strict_types=1);

namespace App\Durable\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * Le pas qui parle au prestataire de paiement.
 *
 * C'est une activité et non du code de workflow parce qu'elle a le droit d'échouer et d'être
 * réessayée : c'est exactement ce qu'une activité est.
 */
interface EncaissementActivityInterface
{
    /**
     * @param int $montant en centimes
     *
     * @return array{recu: string, encaisse: int}
     */
    #[AsActivityMethod('encaisser')]
    public function encaisser(string $commande, int $montant, string $devise): array;
}
