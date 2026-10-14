<?php

declare(strict_types=1);

namespace unit\DurableBundle\Fixtures;

use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;

/**
 * A signal named after one of the stub's own verbs.
 */
#[AsWorkflow('WithAReservedSignal')]
final class WorkflowWithAReservedSignal
{
    #[AsWorkflowMethod]
    public function run(): void {}

    #[AsSignalMethod('stop')]
    public function Cancel(): void {}
}
