<?php

namespace unit\DurableRector\Fixture\Activity;

// durable-rector: activity prefix "Order_" cannot become a Durable activity name — name the activities by hand
#[\Temporal\Activity\ActivityInterface(prefix: 'Order_')]
interface AlreadyMarkedPrefixActivities
{
    public function charge(string $orderId): string;
}

#[\Temporal\Activity\ActivityInterface(prefix: 'Order.')]
interface AlreadyMarkedNameActivities
{
    public const CHARGE = 'charge';

    // durable-rector: activity method name is not a literal — name this activity by hand
    #[\Temporal\Activity\ActivityMethod(name: self::CHARGE)]
    public function charge(string $orderId): string;
}

?>
