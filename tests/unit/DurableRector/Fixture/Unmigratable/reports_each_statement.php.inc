<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Workflow;

class ReportedWorkflow
{
    public function run($mutex)
    {
        $id = yield Workflow::uuid();
        yield Workflow::runLocked($mutex, fn () => null);

        if (yield Workflow::await(fn () => true)) {
            return Workflow::getInfo()->execution->getID();
        }

        return $id;
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Workflow;

class ReportedWorkflow
{
    public function run($mutex)
    {
        // durable-rector: Workflow::uuid() — sideEffect() is the equivalent, and it changes when the value is captured — a review, not a rename
        $id = yield Workflow::uuid();
        // durable-rector: Workflow::runLocked() — no mutex; a workflow is single-threaded here, so what the lock protected may not need protecting
        yield Workflow::runLocked($mutex, fn () => null);

        if (yield Workflow::await(fn () => true)) {
            // durable-rector: Workflow::getInfo() — the environment exposes executionId() and nothing else of the run
            return Workflow::getInfo()->execution->getID();
        }

        return $id;
    }
}

?>
