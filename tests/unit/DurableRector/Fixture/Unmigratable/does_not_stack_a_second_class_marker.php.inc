<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Exception\Failure\ServerFailure;

// durable-rector: ServerFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
class AlreadyMarkedServerFailure extends ServerFailure
{
}

?>
