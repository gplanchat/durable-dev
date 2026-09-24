<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Workflow;

class SagaWorkflow
{
    public function run()
    {
        $saga = new Workflow\Saga();

        return $saga;
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Workflow;

class SagaWorkflow
{
    public function run()
    {
        // durable-rector: new Saga — Durable\Workflow\Saga is a different shape — new Saga($env), and compensate() awaits instead of yielding
        $saga = new Workflow\Saga();

        return $saga;
    }
}

?>
