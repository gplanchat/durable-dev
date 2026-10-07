<?php

namespace unit\DurableRector\Fixture\Activity;

// The SDK type is '.charge'; an empty contract name would give 'charge'.
#[\Temporal\Activity\ActivityInterface(prefix: '.')]
interface LoneDotActivities
{
    public function charge(string $orderId): string;
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Activity;

// The SDK type is '.charge'; an empty contract name would give 'charge'.
// durable-rector: activity prefix "." cannot become a Durable activity name — name the activities by hand
#[\Temporal\Activity\ActivityInterface(prefix: '.')]
interface LoneDotActivities
{
    public function charge(string $orderId): string;
}

?>
