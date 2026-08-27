<?php

declare(strict_types=1);

namespace unit\DurableRector\Source;

use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

/** The type the server knows is not the interface's short name. */
#[WorkflowInterface]
interface RenamedWorkflowContract
{
    #[WorkflowMethod(name: 'checkout-v2')]
    public function run();
}
