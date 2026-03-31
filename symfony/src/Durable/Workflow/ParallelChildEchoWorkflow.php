<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use App\Durable\DurableSampleWorkflows;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Parent qui lance deux {@link EchoChildWorkflow} en parallèle (deux sous-workflows, chacun une activité echo).
 * Utile pour exercer le profiler : dispatch multiples, journal parent + enfants, activités entrelacées.
 *
 * Optionnellement, une pause durable ({@see WorkflowEnvironment::delay}) avant les enfants (ex. 10 s en démo HTTP).
 */
#[Workflow('ParallelChildEchoWorkflow')]
final class ParallelChildEchoWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(string $first = 'alpha', string $second = 'beta', float $pauseSeconds = 0.0): array
    {
        if ($pauseSeconds > 0.0) {
            $this->environment->delay($pauseSeconds, 'pause avant sous-workflows en parallèle');
        }

        return $this->environment->all(
            $this->environment->scheduleChildWorkflow(DurableSampleWorkflows::ECHO_CHILD, ['text' => $first]),
            $this->environment->scheduleChildWorkflow(DurableSampleWorkflows::ECHO_CHILD, ['text' => $second]),
        );
    }
}
