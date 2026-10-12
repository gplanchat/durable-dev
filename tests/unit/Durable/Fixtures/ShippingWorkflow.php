<?php

declare(strict_types=1);

namespace unit\Durable\Fixtures;

use Gplanchat\Durable\Attribute\AsQueryMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsUpdateMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow('shipping')]
final class ShippingWorkflow
{
    /** @return array<string, mixed> */
    #[AsWorkflowMethod]
    public function ship(WorkflowEnvironment $env, string $orderId, int $parcels = 1): array
    {
        return [];
    }

    #[AsSignalMethod('parcel.picked')]
    public function onParcelPicked(string $carrier): void {}

    #[AsQueryMethod('status')]
    public function status(): string
    {
        return 'packing';
    }

    #[AsUpdateMethod('change-address')]
    public function changeAddress(string $street, string $city): bool
    {
        return true;
    }
}
