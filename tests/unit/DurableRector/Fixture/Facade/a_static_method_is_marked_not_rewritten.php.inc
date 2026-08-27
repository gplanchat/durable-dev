<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Workflow;

class StaticHelperWorkflow
{
    public static function stub()
    {
        return Workflow::newActivityStub(SomeActivities::class);
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Workflow;

class StaticHelperWorkflow
{
    // durable-rector: a static method has no $this — move this to an instance method, or pass the environment in
    public static function stub()
    {
        return Workflow::newActivityStub(SomeActivities::class);
    }
}

?>
