<?php

declare(strict_types=1);

namespace App\Tests\Functional\Fixture;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * A workflow whose only step is a timer: the smallest run that needs a worker to come back to it.
 * Registered in the `test` environment only, by config/packages/test/durable.yaml.
 */
#[AsWorkflow(self::TYPE)]
final class NapWorkflow
{
    public const TYPE = 'nap';

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): string
    {
        $env->sleep(1);

        return 'rested';
    }
}
