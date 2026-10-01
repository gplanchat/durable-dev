<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Workflow;

class VersionReportedWorkflow
{
    public function run()
    {
        $version = yield Workflow::getVersion('add-discount', 1, 2);
        $short = yield Workflow::getVersion('audit', 1);
        $promise = Workflow::getVersion('fee', 1, 2);

        if ((yield Workflow::getVersion('fee', Workflow::DEFAULT_VERSION, 1)) === Workflow::DEFAULT_VERSION) {
            return 0;
        }

        return $version;
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Workflow;

class VersionReportedWorkflow
{
    public function run()
    {
        $version = yield Workflow::getVersion('add-discount', 1, 2);
        // durable-rector: Workflow::getVersion() with 2 arguments — version() takes a change id, a minimum and a maximum; write the call by hand
        $short = yield Workflow::getVersion('audit', 1);
        // durable-rector: Workflow::getVersion() not yielded — it returns a promise there, and version() returns the int; rewrite the code that consumes it by hand
        $promise = Workflow::getVersion('fee', 1, 2);

        // durable-rector: Workflow::DEFAULT_VERSION — ChangePoint::DEFAULT_VERSION is the same -1; once temporal/sdk is removed this reference no longer resolves
        if ((yield Workflow::getVersion('fee', Workflow::DEFAULT_VERSION, 1)) === Workflow::DEFAULT_VERSION) {
            return 0;
        }

        return $version;
    }
}

?>
