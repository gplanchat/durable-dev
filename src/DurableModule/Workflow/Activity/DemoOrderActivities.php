<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Magento\Workflow\Activity;

/**
 * L'implémentation de la démonstration : trois étapes qui ne font que se nommer.
 *
 * Elle n'a rien de Magento non plus, et c'est voulu — ce qu'elle sert à montrer est que les noms
 * d'activité viennent des attributs du contrat, pas de chaînes recopiées dans la commande.
 */
/*
 * Pas `final` : le conteneur de Magento l'instancie, donc il engendre un `Interceptor` qui l'étend.
 * Même contrainte d'hôte que pour la commande et la fabrique, et le message d'erreur ne nomme
 * toujours pas le mot-clé.
 */
class DemoOrderActivities implements OrderActivities
{
    public function charge(string $orderId): string
    {
        return 'charge:' . $orderId;
    }

    public function reserveStock(string $orderId): string
    {
        return 'reserve:' . $orderId;
    }

    public function notifyCustomer(string $orderId): string
    {
        return 'notify:' . $orderId;
    }
}
