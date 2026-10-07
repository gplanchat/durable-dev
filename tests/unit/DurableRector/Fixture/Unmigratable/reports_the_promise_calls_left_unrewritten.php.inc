<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Promise;

class PromiseLeftoversWorkflow
{
    public function run(array $promises)
    {
        $first = yield Promise::race($promises);
        $none = yield Promise::all();
        $some = yield Promise::some($promises);
        $all = yield Promise::all($promises);

        return [$first, $none, $some, $all];
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Promise;

class PromiseLeftoversWorkflow
{
    public function run(array $promises)
    {
        // durable-rector: Promise::race() — no Durable equivalent — decide by hand
        $first = yield Promise::race($promises);
        // durable-rector: Promise::all() — no Durable equivalent — decide by hand
        $none = yield Promise::all();
        // durable-rector: some() without a count — pass the count to $env->some() by hand
        $some = yield Promise::some($promises);
        $all = yield Promise::all($promises);

        return [$first, $none, $some, $all];
    }
}

?>
