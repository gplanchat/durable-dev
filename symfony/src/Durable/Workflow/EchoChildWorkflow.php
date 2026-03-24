<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use App\Durable\Activity\EchoActivity;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('EchoChildWorkflow')]
final class EchoChildWorkflow
{
    private readonly ActivityStub $echo;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->echo = $environment->activityStub(EchoActivity::class);
    }

    #[WorkflowMethod]
    public function run(string $text = ''): string
    {
        return $this->environment->await($this->echo->echoUpper($text));
    }
}
