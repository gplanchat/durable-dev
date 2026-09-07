<?php

declare(strict_types=1);

namespace App\Samples\Workflow\CancellationScope;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Inspired by samples-php CancellationScope: several greetings in parallel, the workflow returns
 * the first one to finish (`WorkflowEnvironment::any`).
 */
#[AsWorkflow('Samples_CancellationScope_Race')]
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

    #[AsWorkflowMethod]
    public function run(): string
    {
        $winner = $this->environment->await($this->environment->any(
            $this->greetingA->composeGreeting('A'),
            $this->greetingB->composeGreeting('B'),
            $this->greetingC->composeGreeting('C'),
        ));

        return (string) $winner;
    }
}
