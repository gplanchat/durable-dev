<?php

declare(strict_types=1);

namespace App\Samples\Workflow\ActivityRetry;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;
use InvalidArgumentException;

/**
 * Port de samples-php ActivityRetry : politique de retry sur l’activité (max attempts, backoff, non-retryable).
 */
#[Workflow('Samples_ActivityRetry_Greeting')]
final class ActivityRetryGreetingWorkflow
{
    private readonly ActivityStub $greeting;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->greeting = $environment->activityStub(
            GreetingActivityInterface::class,
            ActivityOptions::default()
                ->withMaxAttempts(5)
                ->withInitialInterval(1.0)
                ->withScheduleToCloseTimeoutSeconds(10.0)
                ->withNonRetryableExceptions([InvalidArgumentException::class])
        );
    }

    #[WorkflowMethod]
    public function run(string $name = 'World'): string
    {
        return $this->environment->await($this->greeting->composeGreeting($name));
    }
}
