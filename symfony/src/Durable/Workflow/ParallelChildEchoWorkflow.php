<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Parent qui lance deux {@link EchoChildWorkflow} en parallèle (deux sous-workflows, chacun une activité echo).
 * Utile pour exercer le profiler : dispatch multiples, journal parent + enfants, activités entrelacées.
 *
 * Optionnellement, une pause durable ({@see WorkflowEnvironment::delay}) avant les enfants (ex. 10 s en démo HTTP).
 */
#[AsWorkflow('ParallelChildEchoWorkflow')]
final class ParallelChildEchoWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[AsWorkflowMethod]
    public function run(string $first = 'alpha', string $second = 'beta', float $pauseSeconds = 0.0): array
    {
        if ($pauseSeconds > 0.0) {
            $this->environment->sleep($pauseSeconds, 'pause avant sous-workflows en parallèle');
        }

        // Deux enfants démarrés, aucun attendu, puis les deux assemblés : c'est exactement ce
        // qu'un stub qui attendait pour l'appelant ne savait pas exprimer, et pourquoi ce
        // workflow nommait son enfant par une chaîne.
        $child = $this->environment->childWorkflowStub(EchoChildWorkflow::class);

        return $this->environment->await($this->environment->all(
            $child->run($first),
            $child->run($second),
        ));
    }
}
