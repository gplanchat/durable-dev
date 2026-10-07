<?php

namespace unit\DurableRector\Fixture\Activity;

#[\Temporal\Activity\ActivityInterface(prefix: ComputedPrefixActivities::PREFIX)]
interface ComputedPrefixActivities
{
    public const PREFIX = 'Order.';

    public function charge(string $orderId): string;
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Activity;

// durable-rector: activity prefix "ComputedPrefixActivities::PREFIX" cannot become a Durable activity name — name the activities by hand
#[\Temporal\Activity\ActivityInterface(prefix: ComputedPrefixActivities::PREFIX)]
interface ComputedPrefixActivities
{
    public const PREFIX = 'Order.';

    public function charge(string $orderId): string;
}

?>
