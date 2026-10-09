<?php

namespace unit\DurableRector\Fixture\Activity;

use Temporal\Exception\Failure\TimeoutFailure;

#[\Temporal\Activity\ActivityInterface(prefix: 'Order.')]
interface FailureMarkedActivities
{
    public const CHARGE = 'charge';

    // durable-rector: TimeoutFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
    #[\Temporal\Activity\ActivityMethod(name: self::CHARGE)]
    public function charge(TimeoutFailure $previous): string;
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Activity;

use Temporal\Exception\Failure\TimeoutFailure;

#[\Temporal\Activity\ActivityInterface(prefix: 'Order.')]
interface FailureMarkedActivities
{
    public const CHARGE = 'charge';

    // durable-rector: TimeoutFailure has no Durable counterpart — once temporal/sdk is removed this reference no longer resolves; decide by hand
    // durable-rector: activity method name is not a literal — name this activity by hand
    #[\Temporal\Activity\ActivityMethod(name: self::CHARGE)]
    public function charge(TimeoutFailure $previous): string;
}

?>
