<?php

declare(strict_types=1);

namespace Gplanchat\DurableSqlSpike\Workflow;

use Gplanchat\Durable\Attribute\AsActivityMethod;

interface SpikeActivities
{
    #[AsActivityMethod(name: 'spike.charge')]
    public function charge(string $orderId, int $pauseSeconds): string;

    #[AsActivityMethod(name: 'spike.ship')]
    public function ship(string $orderId, string $approvedBy): string;
}
