<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Promise;

class PromiseWorkflow
{
    public function run(array $results)
    {
        $all = yield Promise::all($results);
        $some = yield Promise::some($results, 2);

        return $all;
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Promise;

class PromiseWorkflow
{
    public function __construct(private readonly \Gplanchat\Durable\WorkflowEnvironment $environment)
    {
    }
    public function run(array $results)
    {
        $all = $this->environment->await($this->environment->all(...$results));
        $some = $this->environment->await($this->environment->some(2, ...$results));

        return $all;
    }
}

?>
