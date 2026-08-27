<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Promise;
use Temporal\Workflow;

class RaceWorkflow
{
    public function run($activities)
    {
        $work = $activities->charge('x');
        $deadline = Workflow::timer(60);

        $done = yield Workflow::awaitWithTimeout(30, fn () => true);

        return yield Promise::any([$work, $deadline]);
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Promise;
use Temporal\Workflow;

class RaceWorkflow
{
    public function __construct(private readonly \Gplanchat\Durable\WorkflowEnvironment $environment)
    {
    }
    public function run($activities)
    {
        $work = $activities->charge('x');
        $deadline = $this->environment->timer(60);

        $done = $this->environment->await(fn () => true, 30);

        return $this->environment->await($this->environment->any($work, $deadline));
    }
}

?>
