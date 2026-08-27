<?php

namespace unit\DurableRector\Fixture\Workflow;

use unit\DurableRector\Source\OrderWorkflowContract;

#[\Gplanchat\Durable\Attribute\Workflow(name: 'already-chosen')]
final class HalfMigratedWorkflow implements OrderWorkflowContract
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
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Workflow;

use unit\DurableRector\Source\OrderWorkflowContract;

#[\Gplanchat\Durable\Attribute\Workflow(name: 'already-chosen')]
final class HalfMigratedWorkflow implements OrderWorkflowContract
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
}

?>
