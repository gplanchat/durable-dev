<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Magento\Workflow\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * Le contrat de la démonstration : la panne que l'intégration existe pour retirer,
 * réduite à trois étapes — encaisser, réserver, notifier.
 *
 * C'est l'exemple d'OST003 mot pour mot : « a consumer that dies half way through
 * an order ». La commande est encaissée, le stock ne l'est pas.
 */
interface OrderActivities
{
    #[AsActivityMethod(name: 'durable.demo.charge')]
    public function charge(string $orderId): string;

    #[AsActivityMethod(name: 'durable.demo.reserve')]
    public function reserveStock(string $orderId): string;

    #[AsActivityMethod(name: 'durable.demo.notify')]
    public function notifyCustomer(string $orderId): string;
}
