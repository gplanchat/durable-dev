<?php

namespace unit\DurableRector\Fixture\Activity;

// A non-empty contract name is joined with a dot; 'Order_' would come back as 'Order_.charge'.
#[\Temporal\Activity\ActivityInterface(prefix: 'Order_')]
interface UnderscoredActivities
{
    public function charge(string $orderId): string;
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Activity;

// A non-empty contract name is joined with a dot; 'Order_' would come back as 'Order_.charge'.
// durable-rector: activity prefix "Order_" cannot become a Durable activity name — name the activities by hand
#[\Temporal\Activity\ActivityInterface(prefix: 'Order_')]
interface UnderscoredActivities
{
    public function charge(string $orderId): string;
}

?>
