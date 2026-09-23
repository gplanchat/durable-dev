<?php

declare(strict_types=1);

namespace unit\DurableBundle\Fixtures;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;
use unit\Durable\Fixtures\SuiteActivities;

/**
 * The shape of #419: the stub and the environment arrive as method arguments, no constructor.
 */
#[AsWorkflow('WithArguments')]
final class WorkflowWithArguments
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
