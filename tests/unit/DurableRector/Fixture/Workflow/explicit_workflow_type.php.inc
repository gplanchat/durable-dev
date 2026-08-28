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

#[\Gplanchat\Durable\Attribute\AsWorkflow(name: 'checkout-v2')]
final class RenamedWorkflow implements RenamedWorkflowContract
{
    #[\Gplanchat\Durable\Attribute\AsWorkflowMethod]
    public function run()
    {
    }
}

?>
