<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\DurableProbe\Workflow\Activity\SlowOrderActivities;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Le workflow du §5.3. Rien ne le distingue d'un workflow ordinaire — c'est le sujet : il ne sait
 * pas qu'on va tuer le processus entre son deuxième et son troisième pas.
 */
final class SlowOrderWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(string $orderId, int $pauseSeconds = 0): string
    {
        $activities = $this->environment->activityStub(SlowOrderActivities::class);

        $receipt = $this->environment->await($activities->charge($orderId));
        $this->environment->await($activities->reserveStock($orderId, $pauseSeconds));

        return $this->environment->await($activities->notifyCustomer($receipt));
    }
}
