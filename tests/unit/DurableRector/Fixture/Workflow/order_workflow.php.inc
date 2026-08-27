<?php

namespace unit\DurableRector\Fixture\Workflow;

use unit\DurableRector\Source\OrderWorkflowContract;

final class OrderWorkflow implements OrderWorkflowContract
{
    public function run(string $orderId)
    {
        return $orderId;
    }

    public function cancelOrder(): void
    {
    }

    public function status(): string
    {
        return 'pending';
    }

    public function helper(): void
    {
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Workflow;

use unit\DurableRector\Source\OrderWorkflowContract;

#[\Gplanchat\Durable\Attribute\Workflow(name: 'OrderWorkflowContract')]
final class OrderWorkflow implements OrderWorkflowContract
{
    #[\Gplanchat\Durable\Attribute\WorkflowMethod]
    public function run(string $orderId)
    {
        return $orderId;
    }

    #[\Gplanchat\Durable\Attribute\SignalMethod(name: 'cancel')]
    public function cancelOrder(): void
    {
    }

    #[\Gplanchat\Durable\Attribute\QueryMethod(name: 'status')]
    public function status(): string
    {
        return 'pending';
    }

    public function helper(): void
    {
    }
}

?>
