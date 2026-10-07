<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench\Fixture;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/** One activity, then a one second timer: the two things a worker has to carry. */
#[AsWorkflow(name: 'bench.greet-then-wait')]
final class GreetThenWait
{
    public function __construct(private readonly WorkflowEnvironment $environment) {}

    #[AsWorkflowMethod]
    public function run(string $name): string
    {
        $greeting = $this->environment->await($this->environment->activityStub(Greeter::class)->greet($name));
        $this->environment->sleep(1);

        return $greeting . ', after one second';
    }
}
