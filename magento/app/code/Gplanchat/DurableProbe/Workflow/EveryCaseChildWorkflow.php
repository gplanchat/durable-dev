<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\DurableProbe\Workflow\Activity\EveryCaseActivities;

/**
 * The child of {@see EveryCaseWorkflow}: it succeeds, or it fails, depending on what it is asked.
 *
 * Two executions of the same type, one green and the other red, are what proves that a child row
 * carries its own fate and not its parent's.
 */
final class EveryCaseChildWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(string $caseId, bool $shouldFail = false): string
    {
        $activities = $this->environment->activityStub(EveryCaseActivities::class);
        $result = $this->environment->await($activities->succeed($caseId));

        if ($shouldFail) {
            throw new \RuntimeException('the child gave up on ' . $caseId);
        }

        return 'child:' . $result;
    }
}
