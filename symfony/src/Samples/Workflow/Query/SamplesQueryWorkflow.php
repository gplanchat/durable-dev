<?php

declare(strict_types=1);

namespace App\Samples\Workflow\Query;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Inspiré de samples-php Query : pause durable puis salutation (les « queries » Temporal côté client
 * ne sont pas rejouées ici ; voir {@see \Gplanchat\Durable\Query\WorkflowQueryEvaluator} pour la lecture du journal).
 */
#[AsWorkflow('Samples_Query_Greeting')]
final class SamplesQueryWorkflow
{
    private readonly ActivityStub $greeting;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->greeting = $environment->activityStub(
            GreetingActivityInterface::class,
        );
    }

    #[AsWorkflowMethod]
    public function run(string $name = 'World'): string
    {
        $this->environment->timer(2.0);

        return $this->environment->await($this->greeting->composeGreeting($name));
    }
}
