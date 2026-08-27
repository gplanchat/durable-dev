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
        // durable-rector: new Saga — no saga helper — the shape is a deadline and a compensation path, written out by hand
        $saga = new Workflow\Saga();

        return $saga;
    }
}

?>
