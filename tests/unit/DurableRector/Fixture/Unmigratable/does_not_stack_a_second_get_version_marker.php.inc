<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Workflow;

class AlreadyReportedVersionWorkflow
{
    public function run()
    {
        // durable-rector: Workflow::getVersion() — no equivalent yet — workflow versioning is an open change, and a run that reached this marker cannot migrate before it lands
        $version = yield Workflow::getVersion('add-discount', 1, 2);
        // durable-rector: Workflow::getVersion() with 2 arguments — version() takes a change id, a minimum and a maximum; write the call by hand
        $short = yield Workflow::getVersion('audit', 1);
        // durable-rector: Workflow::getVersion() not yielded — it returns a promise there, and version() returns the int; rewrite the code that consumes it by hand
        $promise = Workflow::getVersion('fee', 1, 2);

        return $version;
    }
}
