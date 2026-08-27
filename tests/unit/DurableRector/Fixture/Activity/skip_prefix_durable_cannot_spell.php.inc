<?php

namespace unit\DurableRector\Fixture\Activity;

// Durable always joins with a dot; 'Order_' would come back as 'Order_.charge'.
#[\Temporal\Activity\ActivityInterface(prefix: 'Order_')]
interface UnderscoredActivities
{
    public function charge(string $orderId): string;
}

?>
