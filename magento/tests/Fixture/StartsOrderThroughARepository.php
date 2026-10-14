<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench\Fixture;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\DurableProbe\Repository\SlowOrderRepository;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/** An observer on a placed order that starts its workflow through a declared repository. */
final class StartsOrderThroughARepository implements ObserverInterface
{
    public function __construct(private readonly SlowOrderRepository $orders) {}

    public function execute(Observer $observer): void
    {
        $increment = (string) $observer->getEvent()->getData('increment');

        $this->orders->create(ExecutionId::fromString('order-' . $increment))->start(orderId: $increment, pauseSeconds: 0);
    }
}
