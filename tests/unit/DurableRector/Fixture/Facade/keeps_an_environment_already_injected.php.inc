<?php

namespace unit\DurableRector\Fixture\Facade;

use Gplanchat\Durable\WorkflowEnvironment;
use Temporal\Workflow;

class HalfMigratedWorkflow
{
    public function __construct(private readonly WorkflowEnvironment $environment)
    {
    }

    public function run()
    {
        return yield Workflow::sideEffect(fn () => 1);
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Facade;

use Gplanchat\Durable\WorkflowEnvironment;
use Temporal\Workflow;

class HalfMigratedWorkflow
{
    public function __construct(private readonly WorkflowEnvironment $environment)
    {
    }

    public function run()
    {
        return $this->environment->sideEffect(fn () => 1);
    }
}

?>
