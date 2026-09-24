<?php

declare(strict_types=1);

namespace integration\Durable\Bundle\Support;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;
use unit\Durable\Fixtures\SuiteActivities;

#[AsWorkflow('greet-by-worker')]
final class GreetByWorkerWorkflow
{
    /** @param ActivityStub<SuiteActivities> $greeting */
    #[AsWorkflowMethod]
    public function run(
        string $name,
        #[Activities(SuiteActivities::class)]
        ActivityStub $greeting,
        WorkflowEnvironment $env,
    ): string {
        return $env->await($greeting->greet($name));
    }
}
