<?php

namespace unit\DurableRector\Fixture\Unmigratable;

use Temporal\Workflow;

class AlreadyReportedWorkflow
{
    public function run()
    {
        // durable-rector: Workflow::isReplaying() — no equivalent — replay is not observable from workflow code, by design
        return Workflow::isReplaying();
    }
}

?>
