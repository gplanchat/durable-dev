<?php

declare(strict_types=1);

namespace App\Samples\Workflow\CancellationScope;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Inspiré de samples-php CancellationScope : plusieurs salutations en parallèle, le workflow retourne
 * la première terminée (`WorkflowEnvironment::any`).
 */
#[Workflow('Samples_CancellationScope_Race')]
final class CancellationScopeRaceWorkflow
{
    private readonly ActivityStub $greetingA;

    private readonly ActivityStub $greetingB;

    private readonly ActivityStub $greetingC;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->greetingA = $environment->activityStub(
            GreetingActivityInterface::class,
        );
        $this->greetingB = $environment->activityStub(
            GreetingActivityInterface::class,
        );
        $this->greetingC = $environment->activityStub(
            GreetingActivityInterface::class,
        );
    }

    #[WorkflowMethod]
    public function run(): string
    {
        $winner = $this->environment->any(
            $this->greetingA->composeGreeting('A'),
            $this->greetingB->composeGreeting('B'),
            $this->greetingC->composeGreeting('C'),
        );

        return (string) $winner;
    }
}
