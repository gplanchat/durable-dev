<?php

declare(strict_types=1);

namespace App\Samples\Workflow\Periodic;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Variante légère de samples-php Periodic : plusieurs salutations avec pause durable entre les tours
 * (sans continue-as-new ni sideEffect aléatoire). Les pauses utilisent des secondes entières : le transport
 * Symfony InMemory en test tronque les DelayStamp sous la seconde en 0 s (réveils trop tôt).
 *
 * @return list<string>
 */
#[Workflow('Samples_Periodic_Greeting')]
final class PeriodicGreetingWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(string $name = 'World', int $iterations = 3): array
    {
        $out = [];
        $iterations = max(1, min(10, $iterations));
        for ($i = 0; $i < $iterations; ++$i) {
            if ($i > 0) {
                $this->environment->timer(1.0);
            }
            $label = $name.' #'.($i + 1);
            $out[] = $this->environment->await($this->environment->activity(
                'composeGreeting',
                ['name' => $label],
            ));
        }

        return $out;
    }
}
