<?php

declare(strict_types=1);

namespace App\Tests\Functional\Fixture;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * A workflow whose only step is an activity: the smallest run that needs the resume an activity
 * worker sends. Registered in the `test` environment only, by config/packages/test/durable.yaml.
 */
#[AsWorkflow(self::TYPE)]
final class StampWorkflow
{
    public const TYPE = 'stamp';

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): string
    {
        return $env->await($env->activityStub(StampActivity::class)->stamp('order'));
    }
}
