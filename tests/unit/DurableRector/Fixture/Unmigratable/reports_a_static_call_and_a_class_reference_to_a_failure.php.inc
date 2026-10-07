<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\Failure\TerminatedFailure;

class FailureByNameWorkflow
{
    public function run()
    {
        $terminated = TerminatedFailure::class;

        throw ApplicationFailure::nonRetryable('declined');
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\Failure\TerminatedFailure;

class FailureByNameWorkflow
{
    public function run()
    {
        // durable-rector: TerminatedFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
        $terminated = TerminatedFailure::class;

        // durable-rector: ApplicationFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
        throw ApplicationFailure::nonRetryable('declined');
    }
}

?>
