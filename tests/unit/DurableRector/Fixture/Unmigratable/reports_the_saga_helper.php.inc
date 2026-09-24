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
        // durable-rector: new Saga — Durable\Workflow\Saga is a different shape — each compensation calls $env->await() itself, and compensate() does not yield
        $saga = new Workflow\Saga();

        return $saga;
    }
}

?>
