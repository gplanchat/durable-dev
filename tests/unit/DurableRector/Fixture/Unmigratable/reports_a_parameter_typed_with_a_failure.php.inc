<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\Failure\TerminatedFailure;

class FailureHandler
{
    public function handle(ApplicationFailure $failure): void
    {
    }
}

function report(?TerminatedFailure $failure, \Temporal\Exception\Failure\ServerFailure|\RuntimeException $other): void
{
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\Failure\TerminatedFailure;

class FailureHandler
{
    // durable-rector: ApplicationFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
    public function handle(ApplicationFailure $failure): void
    {
    }
}

// durable-rector: TerminatedFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
// durable-rector: ServerFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
function report(?TerminatedFailure $failure, \Temporal\Exception\Failure\ServerFailure|\RuntimeException $other): void
{
}

?>
