<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;

class OptionsWorkflow
{
    public function options()
    {
        return ActivityOptions::new()->withRetryOptions(RetryOptions::new());
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;

class OptionsWorkflow
{
    public function options()
    {
        // durable-rector: ActivityOptions::new() — Durable\Activity\ActivityOptions is a different shape — ActivityOptions::of() with ActivityTimeouts and RetryLimit
        // durable-rector: RetryOptions::new() — retry is a RetryLimit on the activity options here
        return ActivityOptions::new()->withRetryOptions(RetryOptions::new());
    }
}

?>
