<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Workflow;

class ReportedWorkflow
{
    public function run($mutex)
    {
        $version = yield Workflow::getVersion('change', 1, 2);
        yield Workflow::runLocked($mutex, fn () => null);

        if (yield Workflow::await(fn () => true)) {
            return Workflow::getInfo()->execution->getID();
        }

        return $version;
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
        // durable-rector: Workflow::getVersion() — no equivalent yet — workflow versioning is an open change, and a run that reached this marker cannot migrate before it lands
        $version = yield Workflow::getVersion('change', 1, 2);
        // durable-rector: Workflow::runLocked() — no mutex; a workflow is single-threaded here, so what the lock protected may not need protecting
        yield Workflow::runLocked($mutex, fn () => null);

        if (yield Workflow::await(fn () => true)) {
            // durable-rector: Workflow::getInfo() — the environment exposes executionId() and nothing else of the run
            return Workflow::getInfo()->execution->getID();
        }

        return $version;
    }
}

?>
