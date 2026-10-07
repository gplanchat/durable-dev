<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Promise;

class EmptyAnyWorkflow
{
    public function run()
    {
        return yield Promise::any();
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Promise;

class EmptyAnyWorkflow
{
    public function run()
    {
        // durable-rector: Promise::any() — no Durable equivalent — decide by hand
        return yield Promise::any();
    }
}

?>
