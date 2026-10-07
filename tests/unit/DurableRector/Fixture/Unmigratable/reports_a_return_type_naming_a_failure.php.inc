<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\Failure\TimeoutFailure;

class FailureFactory
{
    public function timeout(): ?TimeoutFailure
    {
        return null;
    }

    public function rethrow(ApplicationFailure $failure): ApplicationFailure
    {
        return $failure;
    }
}

function wrap(): \Temporal\Exception\Failure\ServerFailure|\RuntimeException
{
    return new \RuntimeException();
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\Failure\TimeoutFailure;

class FailureFactory
{
    // durable-rector: TimeoutFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
    public function timeout(): ?TimeoutFailure
    {
        return null;
    }

    // durable-rector: ApplicationFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
    public function rethrow(ApplicationFailure $failure): ApplicationFailure
    {
        return $failure;
    }
}

// durable-rector: ServerFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
function wrap(): \Temporal\Exception\Failure\ServerFailure|\RuntimeException
{
    return new \RuntimeException();
}

?>
