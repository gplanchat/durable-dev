<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Workflow;

class ExtractedWorkflow
{
    public function run(): \Generator
    {
        return yield from $this->chargeWithRetry();
    }

    private function chargeWithRetry(): \Generator
    {
        yield Workflow::timer(1);

        return 'charged';
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Workflow;

class ExtractedWorkflow
{
    public function __construct(private readonly \Gplanchat\Durable\WorkflowEnvironment $environment)
    {
    }
    public function run()
    {
        return $this->chargeWithRetry();
    }

    private function chargeWithRetry()
    {
        $this->environment->sleep(1);

        return 'charged';
    }
}

?>
