<?php

declare(strict_types=1);

namespace App\Durable\Nexus;

use Gplanchat\Durable\Attribute\AsNexusServiceHandler;
use Gplanchat\Durable\Demo\Contracts\Facturation\FacturationContract;
use Gplanchat\Durable\Demo\Contracts\Facturation\FacturationServed;

/**
 * Le métier sert `facturation`. Il n'en écrit qu'une moitié.
 *
 * La balise nomme `FacturationContract` — le contrat **complet**, celui que l'appelant lit — alors
 * que la classe n'implémente que `FacturationServed`. Ce n'est pas une incohérence : c'est ce qui
 * permet à la passe de vérifier la couverture opération par opération, et de constater que
 * `encaisser` n'a pas de corps ici parce qu'un workflow la réclame.
 *
 * Sans cette séparation en deux interfaces, PHP exigerait ici une méthode `encaisser()` vide, dont
 * le seul rôle serait de dire qu'il n'y a rien à écrire.
 */
#[AsNexusServiceHandler(contract: FacturationContract::class)]
final readonly class FacturationHandler implements FacturationServed
{
    /**
     * Vérifier tient dans le budget d'une tâche : ce sont des règles sur des données qu'on a déjà.
     *
     * @return array{acceptee: bool, motif: string|null}
     */
    public function verifier(string $commande, int $montant, string $devise): array
    {
        if ('EUR' !== strtoupper($devise)) {
            return ['acceptee' => false, 'motif' => \sprintf('devise %s non prise en charge', $devise)];
        }

        if ($montant <= 0) {
            return ['acceptee' => false, 'motif' => 'montant nul ou négatif'];
        }

        if ($montant > 500_000) {
            return ['acceptee' => false, 'motif' => 'au-delà du plafond de 5 000,00 €'];
        }

        return ['acceptee' => true, 'motif' => null];
    }
}
