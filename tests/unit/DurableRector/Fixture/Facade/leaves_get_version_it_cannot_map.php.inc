<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Workflow;

class UnmappedVersionWorkflow
{
    public function run(): \Generator
    {
        $short = yield Workflow::getVersion('audit', 1);
        $promise = Workflow::getVersion('fee', 1, 2);

        return $short;
    }
}

?>
