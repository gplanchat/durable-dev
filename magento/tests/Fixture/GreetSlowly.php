<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench\Fixture;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/** One activity that hangs the first time it runs. */
#[AsWorkflow(name: 'bench.greet-slowly')]
final class GreetSlowly
{
    public function __construct(private readonly WorkflowEnvironment $environment) {}

    #[AsWorkflowMethod]
    public function run(string $name): string
    {
        return $this->environment->await($this->environment->activityStub(SlowGreeter::class)->greet($name));
    }
}
