<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\DurableProbe\Workflow\Activity\EveryCaseActivities;

/**
 * L'enfant de {@see EveryCaseWorkflow} : il réussit, ou il échoue, selon ce qu'on lui demande.
 *
 * Deux exécutions du même type, l'une verte et l'autre rouge, sont ce qui prouve qu'une ligne
 * d'enfant porte son propre sort et non celui de son parent.
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
