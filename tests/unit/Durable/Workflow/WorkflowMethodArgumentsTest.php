<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Nexus\Serving\NexusFulfilmentParameterNames;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
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

#[AsWorkflow('greet-with-options')]
final class GreetWithOptionsWorkflow
{
    /** @param ActivityStub<SuiteActivities> $greeting */
    #[AsWorkflowMethod]
    public function run(
        string $name,
        #[Activities(SuiteActivities::class, attempts: 3, startToClose: 120.0, taskQueue: 'greetings')]
        ActivityStub $greeting,
        WorkflowEnvironment $env,
    ): string {
        return $env->await($greeting->greet($name));
    }
}

#[AsWorkflow('impossible-options')]
final class ImpossibleOptionsWorkflow
{
    /** @param ActivityStub<SuiteActivities> $greeting */
    #[AsWorkflowMethod]
    public function run(
        #[Activities(SuiteActivities::class, attempts: 0)]
        ActivityStub $greeting,
    ): void {}
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

#[AsWorkflow('stub-without-contract')]
final class StubWithoutContractWorkflow
{
    // @phpstan-ignore missingType.generics (wrong on purpose: the loader must refuse this signature)
    #[AsWorkflowMethod]
    public function run(ActivityStub $greeting): void {}
}

#[AsWorkflow('contract-on-a-string')]
final class ContractOnAStringWorkflow
{
    #[AsWorkflowMethod]
    // @phpstan-ignore durable.activities.missingGeneric (wrong on purpose: the loader must refuse this signature)
    public function run(#[Activities(SuiteActivities::class)] string $greeting): void {}
}

interface NotAnActivityContract
{
    public function greet(string $name): string;
}

#[AsWorkflow('not-a-contract')]
final class NotAContractWorkflow
{
    // @phpstan-ignore missingType.generics (wrong on purpose: the loader must refuse this signature)
    #[AsWorkflowMethod]
    // @phpstan-ignore durable.activities.missingGeneric (wrong on purpose: the loader must refuse this signature)
    public function run(#[Activities(NotAnActivityContract::class)] ActivityStub $greeting): void {}
}

#[AsWorkflow('greets-through-a-child')]
final class GreetsThroughAChildWorkflow
{
    #[AsWorkflowMethod]
    public function run(string $name, WorkflowEnvironment $env): string
    {
        return $env->await($env->childWorkflowStub(GreetByArgumentWorkflow::class)->run($name));
    }
}

interface GreetingOperation
{
    public function greet(string $name): string;
}

#[AsWorkflow('names-a-missing-contract')]
final class NamesAMissingContractWorkflow
{
    // @phpstan-ignore missingType.generics (wrong on purpose: the loader must refuse this signature)
    #[AsWorkflowMethod]
    public function run(
        // @phpstan-ignore durable.activities.missingGeneric, argument.type (wrong on purpose: the loader must refuse this signature)
        #[Activities('unit\\Gplanchat\\Durable\\Workflow\\NoSuchContract')]
        ActivityStub $greeting,
    ): void {}
}

final class Tally
{
    public int $count = 0;
}

#[AsWorkflow('counts-into-a-default')]
final class CountsIntoADefaultWorkflow
{
    #[AsWorkflowMethod]
    public function run(Tally $tally = new Tally()): int
    {
        return ++$tally->count;
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

    public function testTheAttributesOptionsReachTheScheduledActivity(): void
    {
        $env = WorkflowTestEnvironment::inMemory(['greet' => static fn(array $p): string => 'Hello, ' . $p['name'] . '!']);

        $env->runWorkflowClass(GreetWithOptionsWorkflow::class, ['name' => 'Ada'], 'exec-options');

        $scheduled = array_values(array_filter(
            iterator_to_array($env->getEventStore()->readStream('exec-options'), false),
            static fn(object $e): bool => $e instanceof \Gplanchat\Durable\Event\ActivityScheduled,
        ));
        self::assertCount(1, $scheduled);
        $options = \Gplanchat\Durable\Activity\ActivityOptions::fromMetadata($scheduled[0]->metadata());
        self::assertSame(3, $options?->retryLimit->maxAttempts());
        self::assertSame(120.0, $options->timeouts->startToClose?->toSeconds());
        self::assertSame('greetings', $options->taskQueue?->name());
    }

    public function testAnImpossibleOptionFailsAtRegistrationAndSaysWhere(): void
    {
        $this->expectExceptionMessage('ImpossibleOptionsWorkflow::run() parameter $greeting: #[Activities] attempts');

        (new WorkflowDefinitionLoader())->load(ImpossibleOptionsWorkflow::class);
    }

    public function testAStubWithoutItsContractFailsAtRegistration(): void
    {
        $this->expectExceptionMessage('StubWithoutContractWorkflow::run() parameter $greeting is an ActivityStub without #[Activities(Contract::class)]');

        (new WorkflowDefinitionLoader())->load(StubWithoutContractWorkflow::class);
    }

    public function testAContractOnAParameterThatIsNotAStubFailsAtRegistration(): void
    {
        $this->expectExceptionMessage('ContractOnAStringWorkflow::run() parameter $greeting carries #[Activities] but is typed string, expected ActivityStub');

        (new WorkflowDefinitionLoader())->load(ContractOnAStringWorkflow::class);
    }

    public function testATypeWithNoActivityMethodIsNotAContract(): void
    {
        $this->expectExceptionMessage(NotAnActivityContract::class . ' declares no #[AsActivityMethod]');

        (new WorkflowDefinitionLoader())->load(NotAContractWorkflow::class);
    }

    public function testInjectedParametersAreNotPartOfTheInputNames(): void
    {
        self::assertSame(['name' => false], (new WorkflowDefinitionLoader())->workflowMethodParameters(GreetByArgumentWorkflow::class));
    }

    public function testAParentStartsAChildWithItsInputArgumentsOnly(): void
    {
        $env = WorkflowTestEnvironment::inMemory(['greet' => static fn(array $p): string => 'Hello, ' . $p['name'] . '!']);
        $env->registerWorkflowClass(GreetByArgumentWorkflow::class);

        self::assertSame('Hello, Ada!', $env->runWorkflowClass(GreetsThroughAChildWorkflow::class, ['name' => 'Ada']));
    }

    public function testAContractThatDoesNotExistFailsAtRegistration(): void
    {
        $this->expectExceptionMessage('#[Activities(unit\\Gplanchat\\Durable\\Workflow\\NoSuchContract)] names no class or interface');

        (new WorkflowDefinitionLoader())->load(NamesAMissingContractWorkflow::class);
    }

    public function testANexusFulfilmentMatchesOnTheInputParametersOnly(): void
    {
        $this->expectNotToPerformAssertions();

        // `$greeting` and `$env` are not in the operation's payload, and must not be reported as orphans.
        NexusFulfilmentParameterNames::assertMatch('test', GreetingOperation::class, 'greet', 'greet', GreetByArgumentWorkflow::class);
    }

    public function testADefaultIsBuiltForEachExecution(): void
    {
        $env = WorkflowTestEnvironment::inMemory();

        // A `new` default shared across executions would leak state from one run into the next.
        self::assertSame(1, $env->runWorkflowClass(CountsIntoADefaultWorkflow::class));
        self::assertSame(1, $env->runWorkflowClass(CountsIntoADefaultWorkflow::class));
    }
}
