<?php

declare(strict_types=1);

namespace App\Samples\Workflow\MtlsHelloWorld;

use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/** Minimal equivalent of samples-php MtlsHelloWorld (a greeting; mTLS lives on the Temporal infrastructure side). */
#[AsWorkflow('Samples_MtlsHelloWorld_Greeting')]
final class MtlsHelloWorldWorkflow
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
        return $this->environment->await($this->greeting->composeGreeting($name));
    }
}
