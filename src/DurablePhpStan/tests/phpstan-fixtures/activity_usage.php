<?php

declare(strict_types=1);

namespace Gplanchat\Durable\PHPStan\Fixtures;

use Gplanchat\Durable\Attribute\ActivityMethod;
use Gplanchat\Durable\WorkflowEnvironment;

interface SampleActivitiesInterface
{
    #[ActivityMethod('greet')]
    public function greet(string $name): string;
}

final class FixtureWorkflow
{
    public function run(WorkflowEnvironment $env): void
    {
        $stub = $env->activityStub(SampleActivitiesInterface::class);
        $a = $stub->greet('x');
        // Intention: $a should be inferred as Awaitable<string> with the extension.
        $env->await($a);
    }
}
