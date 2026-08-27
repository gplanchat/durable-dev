<?php

declare(strict_types=1);

namespace unit\DurableRector\Source;

use Temporal\Workflow\QueryMethod;
use Temporal\Workflow\SignalMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface OrderWorkflowContract
{
    #[WorkflowMethod]
    public function run(string $orderId);

    #[SignalMethod(name: 'cancel')]
    public function cancelOrder(): void;

    #[QueryMethod]
    public function status(): string;
}
