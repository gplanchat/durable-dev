<?php

namespace unit\DurableRector\Fixture\Unmigratable;

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

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Workflow;

class ManyConditionsWorkflow
{
    public function run()
    {
        // durable-rector: Workflow::await() with more than one condition — await() takes one condition and a deadline here, so a second condition would become a timeout; combine them by hand
        yield Workflow::await(fn () => $this->a, fn () => $this->b);
        yield Workflow::await(fn () => $this->a);
    }
}

?>
