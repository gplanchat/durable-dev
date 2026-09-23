<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\SuiteActivities;

#[AsWorkflow('greet-by-argument')]
final class GreetByArgumentWorkflow
{
    /** @param ActivityStub<SuiteActivities> $greeting */
    #[AsWorkflowMethod]
    public function run(
        string $name,
        #[Activities(SuiteActivities::class)]
        ActivityStub $greeting,
        WorkflowEnvironment $env,
    ): string {
        return $env->await($greeting->greet($name));
    }
}

#[AsWorkflow('fan-out-by-argument')]
final class FanOutByArgumentWorkflow
{
    /**
     * @param ActivityStub<SuiteActivities> $first
     * @param ActivityStub<SuiteActivities> $second
     *
     * @return array<int, mixed>
     */
    #[AsWorkflowMethod]
    public function run(
        WorkflowEnvironment $env,
        #[Activities(SuiteActivities::class)]
        ActivityStub $first,
        #[Activities(SuiteActivities::class)]
        ActivityStub $second,
        int $value,
    ): array {
        return $env->await($env->all($first->double($value), $second->double($value + 1)));
    }
}

#[AsWorkflow('whole-input')]
final class WholeInputWorkflow
{
    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    #[AsWorkflowMethod]
    public function run(array $input, WorkflowEnvironment $env): array
    {
        return $input;
    }
}

/**
 * The workflow method receives its stubs and its environment as arguments, the way a controller
 * receives its services (#419). The input is still matched by name; injected parameters are not
 * part of it.
 */
final class WorkflowMethodArgumentsTest extends TestCase
{
    public function testTheMethodReceivesItsStubAndItsEnvironmentWithoutAConstructor(): void
    {
        $env = WorkflowTestEnvironment::inMemory(['greet' => static fn(array $p): string => 'Hello, ' . $p['name'] . '!']);

        self::assertSame('Hello, Ada!', $env->runWorkflowClass(GreetByArgumentWorkflow::class, ['name' => 'Ada']));
    }

    public function testInjectedStubsComposeWithAll(): void
    {
        $env = WorkflowTestEnvironment::inMemory(['double' => static fn(array $p): int => $p['value'] * 2]);

        self::assertSame([4, 6], $env->runWorkflowClass(FanOutByArgumentWorkflow::class, ['value' => 2]));
    }

    public function testAnInjectedEnvironmentDoesNotBreakTheWholeInputForm(): void
    {
        $env = WorkflowTestEnvironment::inMemory();

        self::assertSame(['a' => 1, 'b' => 2], $env->runWorkflowClass(WholeInputWorkflow::class, ['a' => 1, 'b' => 2]));
    }

}
