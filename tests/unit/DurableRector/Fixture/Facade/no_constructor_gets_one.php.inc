<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Workflow;

class BareWorkflow
{
    public function run()
    {
        return yield Workflow::sideEffect(fn () => 1);
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Workflow;

class BareWorkflow
{
    public function __construct(private readonly \Gplanchat\Durable\WorkflowEnvironment $environment)
    {
    }
    public function run()
    {
        return $this->environment->sideEffect(fn () => 1);
    }
}

?>
