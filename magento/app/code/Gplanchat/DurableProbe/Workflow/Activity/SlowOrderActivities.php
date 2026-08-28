<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * Le contrat du §5.3 : encaisser, réserver, notifier — la panne qu'OST003 nomme, réduite à trois
 * étapes dont une traîne assez pour qu'on puisse tuer le processus au milieu.
 *
 * `charge` laisse une trace **observable** : c'est elle qui dit si la carte a été débitée deux
 * fois. Sans effet de bord, « ne re-débite pas » ne se mesure pas, il se croit.
 */
interface SlowOrderActivities
{
    #[AsActivityMethod(name: 'durable.probe.charge')]
    public function charge(string $orderId): string;

    #[AsActivityMethod(name: 'durable.probe.reserve')]
    public function reserveStock(string $orderId, int $pauseSeconds): string;

    #[AsActivityMethod(name: 'durable.probe.notify')]
    public function notifyCustomer(string $receipt): string;
}
