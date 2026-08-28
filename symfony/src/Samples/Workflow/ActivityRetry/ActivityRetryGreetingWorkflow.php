<?php

declare(strict_types=1);

namespace App\Samples\Workflow\ActivityRetry;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;
use InvalidArgumentException;

/**
 * Port de samples-php ActivityRetry : politique de retry sur l’activité (max attempts, backoff, non-retryable).
 */
#[AsWorkflow('Samples_ActivityRetry_Greeting')]
final class ActivityRetryGreetingWorkflow
{
    private readonly ActivityStub $greeting;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->greeting = $environment->activityStub(
            GreetingActivityInterface::class,
            new ActivityOptions(
                RetryLimit::ofAttempts(5),
                Duration::seconds(1.0),
                nonRetryableExceptions: [InvalidArgumentException::class],
                timeouts: ActivityTimeouts::none()->withScheduleToClose(Duration::seconds(10.0)),
            )
        );
    }

    #[AsWorkflowMethod]
    public function run(string $name = 'World'): string
    {
        return $this->environment->await($this->greeting->composeGreeting($name));
    }
}
