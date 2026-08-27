<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Workflow;

class ManyConditionsWorkflow
{
    public function run()
    {
        yield Workflow::await(fn () => $this->a, fn () => $this->b);
        yield Workflow::await(fn () => $this->a);
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Workflow;

class ManyConditionsWorkflow
{
    public function __construct(private readonly \Gplanchat\Durable\WorkflowEnvironment $environment)
    {
    }
    public function run()
    {
        yield Workflow::await(fn () => $this->a, fn () => $this->b);
        $this->environment->await(fn () => $this->a);
    }
}

?>
