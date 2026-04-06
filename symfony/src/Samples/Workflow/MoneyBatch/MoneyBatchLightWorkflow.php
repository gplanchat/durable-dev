<?php

declare(strict_types=1);

namespace App\Samples\Workflow\MoneyBatch;

use App\Samples\Activity\BatchSumActivityInterface;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Variante simplifiée de samples-php MoneyBatch : agrégation des montants (centimes) en une activité.
 */
#[Workflow('Samples_MoneyBatch_Light')]
final class MoneyBatchLightWorkflow
{
    private readonly ActivityStub $batch;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->batch = $environment->activityStub(
            BatchSumActivityInterface::class,
        );
    }

    /**
     * @param list<int> $parts
     */
    #[WorkflowMethod]
    public function run(array $parts = [100, 200, 300]): int
    {
        return $this->environment->await($this->batch->sumParts($parts));
    }
}
