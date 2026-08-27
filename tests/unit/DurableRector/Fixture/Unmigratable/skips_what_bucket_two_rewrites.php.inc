<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Workflow;

class RewritableWorkflow
{
    public function run()
    {
        $activities = Workflow::newActivityStub(SomeActivities::class);
        yield Workflow::timer(3600);
        yield Workflow::sideEffect(fn () => 1);

        return yield $activities->charge('x');
    }
}

?>
