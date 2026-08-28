<?php

declare(strict_types=1);

namespace unit\DurableModule\Fixture;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * Le contrat que les tests du module emploient.
 *
 * Il vit **ici** et non dans le paquet : ce que ces tests éprouvent est le mécanisme de déclaration,
 * pas un workflow en particulier. Un contrat de test dans le paquet publié serait du poids que tout
 * projet consommateur porterait pour rien.
 */
interface OrderActivities
{
    #[AsActivityMethod(name: 'test.order.charge')]
    public function charge(string $orderId): string;

    #[AsActivityMethod(name: 'test.order.reserve')]
    public function reserveStock(string $orderId): string;

    #[AsActivityMethod(name: 'test.order.notify')]
    public function notifyCustomer(string $receipt): string;
}
