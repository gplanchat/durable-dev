<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Testing;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsActivityMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException;
use Gplanchat\Durable\Testing\ActivitySpy;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The harness has to know how to run a **class** workflow, in the production form.
 *
 * Today it only takes a `callable`, so a test workflow is a closure that receives the environment
 * — a signature no real workflow has had since the environment moved to the constructor. That is
 * the only reason forty-seven calls in the suite still use `activity()`: in a closure, there is no
 * constructor in which to build a stub.
 *
 * As long as these tests fail, `activity()` cannot leave the public surface — there would be no
 * replacement to offer the tests.
 *
 * @see openspec/changes/workflow-authoring-surface — tasks 2.1 to 2.4
 */
final class WorkflowClassUnderTestTest extends TestCase
{
    public function testTheEnvironmentReachesTheConstructorAndTheInputReachesTheMethod(): void
    {
        $env = WorkflowTestEnvironment::inMemory([
            'greet' => static fn(array $p): string => 'Hello, ' . $p['name'] . '!',
        ]);

        $result = $env->runWorkflowClass(GreetingWorkflow::class, ['name' => 'Alice']);

        self::assertSame('Hello, Alice!', $result);
    }

    public function testTheDoubleReceivesTheArgumentsThePassedThroughTheStub(): void
    {
        $spy = ActivitySpy::returns('Hello, Bob!');
        $env = WorkflowTestEnvironment::inMemory(['greet' => $spy]);

        $env->runWorkflowClass(GreetingWorkflow::class, ['name' => 'Bob']);

        $spy->assertCalledTimes(1);
        // The stub rebuilds the payload from the named parameters of the contract: that is what
        // makes the typo impossible, and that is what the test has to establish.
        $spy->assertCalledWith(['name' => 'Bob']);
    }

    public function testAFailingActivitySurfacesAsTheWorkflowFailure(): void
    {
        $env = WorkflowTestEnvironment::inMemory([
            'greet' => static function (array $p): never {
                throw new \DomainException('greeting refused');
            },
        ]);

        // An activity that fails without the workflow catching it is reported as an algorithm
        // fault, and the message names the cause — that is what makes the test readable when it
        // breaks, and it is worth pinning.
        $this->expectException(DurableWorkflowAlgorithmFailureException::class);
        $this->expectExceptionMessage('AsWorkflow did not handle activity failure');
        $this->expectExceptionMessage('DomainException: greeting refused');

        $env->runWorkflowClass(GreetingWorkflow::class, ['name' => 'Carol']);
    }

    public function testAWorkflowThatCallsNoActivityNeedsNothingConfigured(): void
    {
        // No activity handler, so no contract resolver to configure: a workflow that schedules
        // nothing must be able to run on a bare harness.
        $result = WorkflowTestEnvironment::inMemory()->runWorkflowClass(
            EchoWorkflow::class,
            ['text' => 'quiet'],
        );

        self::assertSame('quiet', $result);
    }

    public function testTheClosureFormStillRuns(): void
    {
        $env = WorkflowTestEnvironment::inMemory([
            'greet' => static fn(array $p): string => 'Hello, ' . $p['name'] . '!',
        ]);

        // The anonymous form stays: a test that wants three lines must not have to declare a
        // class and a contract in order to write them. What changes is that it is the form of the
        // harness and not that of a workflow.
        $result = $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await(
            $wf->activityStub(GreetingActivities::class)->greet('Dave'),
        ));

        self::assertSame('Hello, Dave!', $result);
    }
}

interface GreetingActivities
{
    #[AsActivityMethod('greet')]
    public function greet(string $name): string;
}

#[AsWorkflow(name: 'greeting')]
final class GreetingWorkflow
{
    private ActivityStub $greetings;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        // One attempt: by default activities retry indefinitely, and a failure would never
        // surface — the workflow would stay blocked until the budget ran out.
        $this->greetings = $environment->activityStub(
            GreetingActivities::class,
            ActivityOptions::of(retryLimit: 1),
        );
    }

    #[AsWorkflowMethod]
    public function run(string $name): string
    {
        /** @var Awaitable<string> $call */
        $call = $this->greetings->greet($name);

        return $this->environment->await($call);
    }
}

#[AsWorkflow(name: 'echo')]
final class EchoWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(string $text): string
    {
        return $text;
    }
}
