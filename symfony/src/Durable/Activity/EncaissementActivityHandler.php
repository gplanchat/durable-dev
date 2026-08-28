<?php

declare(strict_types=1);

namespace App\Durable\Activity;

use Gplanchat\Durable\Attribute\AsActivityHandler;

/**
 * Le prestataire de paiement de la démonstration : il dit oui, et il prend son temps.
 *
 * La lenteur n'est pas décorative. Elle est ce qui rend l'attente **réelle** : sans elle, la
 * démonstration montrerait un aller-retour immédiat déguisé en différé, et le lecteur ne verrait
 * pas la seule chose que la forme différée existe pour montrer — l'appelant qui ne tient rien
 * d'ouvert pendant ce temps.
 */
#[AsActivityHandler(contract: EncaissementActivityInterface::class)]
final class EncaissementActivityHandler implements EncaissementActivityInterface
{
    /**
     * @return array{recu: string, encaisse: int}
     */
    public function encaisser(string $commande, int $montant, string $devise): array
    {
        usleep(1_500_000);

        return [
            'recu' => \sprintf('RECU-%s-%s', strtoupper($devise), $commande),
            'encaisse' => $montant,
        ];
    }
}
