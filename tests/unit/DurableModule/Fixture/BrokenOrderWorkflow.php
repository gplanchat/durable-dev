<?php

declare(strict_types=1);

namespace unit\DurableModule\Fixture;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;

#[AsWorkflow(name: 'test.order.broken')]
final class BrokenOrderWorkflow
{
    #[AsWorkflowMethod]
    public function run(string $orderId): string
    {
        throw new \DomainException('The order ' . $orderId . ' cannot be placed.');
    }
}
