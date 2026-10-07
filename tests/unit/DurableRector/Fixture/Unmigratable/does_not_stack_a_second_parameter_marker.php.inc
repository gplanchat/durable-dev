<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Exception\Failure\TimeoutFailure;

class AlreadyMarkedHandler
{
    // durable-rector: TimeoutFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
    public function handle(TimeoutFailure $failure): void
    {
    }
}

?>
