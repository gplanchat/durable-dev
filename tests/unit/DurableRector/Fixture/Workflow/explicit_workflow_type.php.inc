<?php

namespace unit\DurableRector\Fixture\Workflow;

use unit\DurableRector\Source\RenamedWorkflowContract;

final class RenamedWorkflow implements RenamedWorkflowContract
{
    public function run()
    {
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Workflow;

use unit\DurableRector\Source\RenamedWorkflowContract;

#[\Gplanchat\Durable\Attribute\Workflow(name: 'checkout-v2')]
final class RenamedWorkflow implements RenamedWorkflowContract
{
    #[\Gplanchat\Durable\Attribute\WorkflowMethod]
    public function run()
    {
    }
}

?>
