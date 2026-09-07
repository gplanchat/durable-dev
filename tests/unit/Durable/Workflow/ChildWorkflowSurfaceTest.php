<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\Workflow\ChildWorkflowStub;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * A stub assembles, it does not await.
 *
 * `ChildWorkflowStub::__call()` returned the child's result, already awaited, where
 * `ActivityStub` returns an `Awaitable`. The asymmetry only shows at composition time, and then
 * the typed form cannot express what is wanted: that is why the example application launched its
 * two parallel children by naming them with a string.
 *
 * DUR033 had already settled it — "await() is the only method that awaits" — but it enumerated
 * the methods of the environment, not those of the stub.
 *
 * @see openspec/changes/child-workflow-surface
 */
final class ChildWorkflowSurfaceTest extends TestCase
{
    public function testTheEnvironmentExposesNoChildWorkflowVerb(): void
    {
        $reflection = new \ReflectionClass(WorkflowEnvironment::class);

        $public = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $public[] = $method->getName();
        }

        self::assertNotContains('scheduleChildWorkflow', $public);
        // This one awaited on the caller's behalf, which made it uncomposable by construction.
        self::assertNotContains('executeChildWorkflow', $public);
    }

    public function testAStubCallReturnsAnAwaitableRatherThanTheResult(): void
    {
        $env = WorkflowTestEnvironment::inMemory();
        $env->registerWorkflowClass(EchoChild::class);

        $result = $env->runWorkflowClass(AwaitingParent::class, ['text' => 'hello']);

        self::assertSame('child:hello', $result);
    }

    public function testTwoChildrenCanBeRaced(): void
    {
        $env = WorkflowTestEnvironment::inMemory();
        $env->registerWorkflowClass(EchoChild::class);

        // The case that motivates the whole change: impossible to write as long as the stub
        // awaited, because the first child would have settled before the second even started.
        $result = $env->runWorkflowClass(RacingParent::class, ['first' => 'a', 'second' => 'b']);

        self::assertContains($result, ['child:a', 'child:b']);
    }

    public function testCallingSomethingOtherThanTheEntryMethodFails(): void
    {
        $env = WorkflowTestEnvironment::inMemory();
        $env->registerWorkflowClass(EchoChild::class);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('is not the workflow entry point');

        $env->runWorkflowClass(WrongMethodParent::class, []);
    }
}

#[AsWorkflow(name: 'echo-child')]
final class EchoChild
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(string $text): string
    {
        return 'child:' . $text;
    }
}

#[AsWorkflow(name: 'awaiting-parent')]
final class AwaitingParent
{
    private ChildWorkflowStub $child;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->child = $environment->childWorkflowStub(EchoChild::class);
    }

    #[AsWorkflowMethod]
    public function run(string $text): string
    {
        /** @var Awaitable<string> $call */
        $call = $this->child->run($text);

        return $this->environment->await($call);
    }
}

#[AsWorkflow(name: 'racing-parent')]
final class RacingParent
{
    private ChildWorkflowStub $child;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->child = $environment->childWorkflowStub(EchoChild::class);
    }

    #[AsWorkflowMethod]
    public function run(string $first, string $second): string
    {
        return $this->environment->await($this->environment->any(
            $this->child->run($first),
            $this->child->run($second),
        ));
    }
}

#[AsWorkflow(name: 'wrong-method-parent')]
final class WrongMethodParent
{
    private ChildWorkflowStub $child;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->child = $environment->childWorkflowStub(EchoChild::class);
    }

    #[AsWorkflowMethod]
    public function run(): mixed
    {
        return $this->environment->await($this->child->notTheEntryPoint('x'));
    }
}
