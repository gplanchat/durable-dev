<?php

declare(strict_types=1);

namespace unit\DurableBundle\Fixtures;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;

/**
 * An `ActivityStub` argument that does not name its contract: the loader cannot build it.
 */
#[AsWorkflow('WithAnUnresolvableStub')]
final class WorkflowWithAnUnresolvableStub
{
    #[AsWorkflowMethod]
    public function run(ActivityStub $greeting): void {}
}
