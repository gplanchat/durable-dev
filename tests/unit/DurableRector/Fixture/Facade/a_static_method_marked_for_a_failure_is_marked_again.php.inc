<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Exception\Failure\TimeoutFailure;
use Temporal\Workflow;

class FailureMarkedStaticHelperWorkflow
{
    // durable-rector: TimeoutFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
    public static function stub(TimeoutFailure $previous)
    {
        return Workflow::newActivityStub(SomeActivities::class);
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Exception\Failure\TimeoutFailure;
use Temporal\Workflow;

class FailureMarkedStaticHelperWorkflow
{
    // durable-rector: TimeoutFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
    // durable-rector: a static method has no $this — move this to an instance method, or pass the environment in
    public static function stub(TimeoutFailure $previous)
    {
        return Workflow::newActivityStub(SomeActivities::class);
    }
}

?>
