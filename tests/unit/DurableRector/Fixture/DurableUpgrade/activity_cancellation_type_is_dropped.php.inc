<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityOptions;

final class DropsTheOption
{
    public function __invoke(): ActivityOptions
    {
        return ActivityOptions::of(3, 30, cancellationType: ActivityCancellationType::Abandon);
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityOptions;

final class DropsTheOption
{
    public function __invoke(): ActivityOptions
    {
        return ActivityOptions::of(3, 30);
    }
}

?>
