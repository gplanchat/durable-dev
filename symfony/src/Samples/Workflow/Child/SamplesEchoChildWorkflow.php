<?php

declare(strict_types=1);

namespace App\Samples\Workflow\Child;

use App\Durable\Activity\EchoActivityInterface;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('Samples_Child_EchoChild')]
final class SamplesEchoChildWorkflow
{
    private readonly ActivityStub $echo;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->echo = $environment->activityStub(
            EchoActivityInterface::class,
        );
    }

    #[WorkflowMethod]
    public function run(string $text = ''): string
    {
        return $this->environment->await($this->echo->echoUpper($text));
    }
}
