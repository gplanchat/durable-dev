<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\DurableProbe\Workflow\Activity\SlowOrderActivities;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * The §5.3 workflow. Nothing distinguishes it from an ordinary workflow — that is the point: it
 * does not know the process is going to be killed between its second and its third step.
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
