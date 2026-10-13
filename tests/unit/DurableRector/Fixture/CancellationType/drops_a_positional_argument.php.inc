<?php

namespace unit\DurableRector\Fixture\CancellationType;

use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityOptions;

final class PositionalArguments
{
    public function options(): array
    {
        return [
            ActivityOptions::of(5, 120, 2.5, [\RuntimeException::class], 'billing', 3.0, 60.0, 'Charge', null, ActivityCancellationType::Abandon),
            ActivityOptions::of(5, 120, 2.5, [], 'billing', 3.0, 60.0, 'Charge', null, ActivityCancellationType::Abandon),
            new ActivityOptions(null, null, 2.0, null, [], null, null, null, ActivityCancellationType::Abandon, 'Charge'),
            new ActivityOptions(null, null, 2.0, null, [], null, null, null, ActivityCancellationType::Abandon),
        ];
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\CancellationType;

use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityOptions;

final class PositionalArguments
{
    public function options(): array
    {
        return [
            ActivityOptions::of(5, 120, 2.5, [\RuntimeException::class], 'billing', 3.0, 60.0, 'Charge', null),
            ActivityOptions::of(5, 120, 2.5, [], 'billing', 3.0, 60.0, 'Charge', null),
            new ActivityOptions(null, null, 2.0, null, [], null, null, null, 'Charge'),
            new ActivityOptions(null, null, 2.0, null, [], null, null, null),
        ];
    }
}

?>
