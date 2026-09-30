<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Sleeps on a short timer, then completes: the smallest run that only a fired timer can finish.
 */
#[AsWorkflow('Nap')]
final class NapWorkflow
{
    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): string
    {
        $env->sleep(2);

        return 'rested';
    }
}
