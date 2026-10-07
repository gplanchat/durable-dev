<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Exception\Failure\TimeoutFailure;

#[\Temporal\Activity\ActivityInterface(prefix: 'Order.')]
interface ComputedNameFailureActivities
{
    public const CHARGE = 'charge';

    // durable-rector: activity method name is not a literal — name this activity by hand
    #[\Temporal\Activity\ActivityMethod(name: self::CHARGE)]
    public function charge(TimeoutFailure $previous): string;
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Exception\Failure\TimeoutFailure;

#[\Temporal\Activity\ActivityInterface(prefix: 'Order.')]
interface ComputedNameFailureActivities
{
    public const CHARGE = 'charge';

    // durable-rector: activity method name is not a literal — name this activity by hand
    // durable-rector: TimeoutFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
    #[\Temporal\Activity\ActivityMethod(name: self::CHARGE)]
    public function charge(TimeoutFailure $previous): string;
}

?>
