<?php

namespace unit\DurableRector\Fixture\Activity;

#[\Temporal\Activity\ActivityInterface(prefix: 'Order.')]
interface ComputedNameActivities
{
    public const CHARGE = 'charge';

    #[\Temporal\Activity\ActivityMethod(name: self::CHARGE)]
    public function charge(string $orderId): string;

    public function refund(string $orderId): string;
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Activity;

#[\Temporal\Activity\ActivityInterface(prefix: 'Order.')]
interface ComputedNameActivities
{
    public const CHARGE = 'charge';

    // durable-rector: activity method name is not a literal — name this activity by hand
    #[\Temporal\Activity\ActivityMethod(name: self::CHARGE)]
    public function charge(string $orderId): string;

    public function refund(string $orderId): string;
}

?>
