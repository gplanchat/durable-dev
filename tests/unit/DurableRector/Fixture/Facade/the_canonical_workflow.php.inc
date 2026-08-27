<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Workflow;

class GreetingWorkflow
{
    private $activities;

    public function __construct()
    {
        $this->activities = Workflow::newActivityStub(GreetingActivities::class);
    }

    public function greet(string $name): \Generator
    {
        yield Workflow::timer(3600);

        return yield $this->activities->composeGreeting($name);
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Workflow;

class GreetingWorkflow
{
    private $activities;

    public function __construct(private readonly \Gplanchat\Durable\WorkflowEnvironment $environment)
    {
        $this->activities = $this->environment->activityStub(GreetingActivities::class);
    }

    public function greet(string $name)
    {
        $this->environment->sleep(3600);

        return $this->environment->await($this->activities->composeGreeting($name));
    }
}

?>
