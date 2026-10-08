<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Workflow;

class OldMarkerWorkflow
{
    public function run()
    {
        // keep this note
        // durable-rector: Workflow::getVersion() — no equivalent yet — workflow versioning is an open change, and a run that reached this marker cannot migrate before it lands
        $version = yield Workflow::getVersion('add-discount', 1, 2);
        // durable-rector: Workflow::getVersion() — no equivalent yet — workflow versioning is an open change, and a run that reached this marker cannot migrate before it lands
        $short = yield Workflow::getVersion('audit', 1);

        return $version;
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Workflow;

class OldMarkerWorkflow
{
    public function __construct(private readonly \Gplanchat\Durable\WorkflowEnvironment $environment)
    {
    }
    public function run()
    {
        // keep this note
        $version = $this->environment->version('add-discount', 1, 2);
        // durable-rector: Workflow::getVersion() — no equivalent yet — workflow versioning is an open change, and a run that reached this marker cannot migrate before it lands
        $short = yield Workflow::getVersion('audit', 1);

        return $version;
    }
}

?>
