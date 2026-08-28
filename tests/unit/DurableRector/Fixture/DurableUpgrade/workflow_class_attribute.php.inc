<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;

#[Workflow(name: 'checkout')]
final class Checkout
{
    #[WorkflowMethod]
    public function run(): void
    {
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;

#[\Gplanchat\Durable\Attribute\AsWorkflow(name: 'checkout')]
final class Checkout
{
    #[\Gplanchat\Durable\Attribute\AsWorkflowMethod]
    public function run(): void
    {
    }
}

?>
