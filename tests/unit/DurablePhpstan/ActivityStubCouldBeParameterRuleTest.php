<?php

declare(strict_types=1);

namespace unit\DurablePhpstan;

use Gplanchat\Durable\PHPStan\Rules\ActivityStubCouldBeParameterRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The rule only informs: it reports a stub the developer can move to an `#[Activities]` parameter
 * by hand, and stays silent whenever the move would change what runs.
 *
 * @extends RuleTestCase<ActivityStubCouldBeParameterRule>
 */
final class ActivityStubCouldBeParameterRuleTest extends RuleTestCase
{
    private const DIR = __DIR__ . '/Fixtures/StubCouldBeParameter/';

    private const TIP = 'Keep activityStub() when the options are computed at run time, or when a signal, update or helper method, or a closure, uses the stub. The rule does not see another method calling the workflow method, __call, reflection or get_object_vars() reaching the property, or the local name used before the stub is built. Otherwise ignore this with the identifier durable.activityStubCouldBeParameter.';

    private const REFUSED = ' Warning: after the move, the worker refuses to register the workflow: %s.';

    protected function getRule(): Rule
    {
        return new ActivityStubCouldBeParameterRule(self::createReflectionProvider());
    }

    public function testALocalStubWithoutOptionsIsReported(): void
    {
        $this->analyse([self::DIR . 'local-stub.php'], [
            [self::message('run', 'orders', ''), 15, self::TIP],
        ]);
    }

    public function testAConstructorStubReadOnlyByTheWorkflowMethodIsReported(): void
    {
        $this->analyse([self::DIR . 'constructor-stub.php'], [
            [self::message('run', 'orders', ''), 17, self::TIP],
        ]);
    }

    public function testLiteralPositionalOptionsAreSpelledAsAttributeFields(): void
    {
        $this->analyse([self::DIR . 'literal-options.php'], [
            [self::message('run', 'orders', ', attempts: 5, startToClose: 120, initialInterval: 2.5, nonRetryable: [\RuntimeException::class], taskQueue: \'billing\', backoffCoefficient: 3.0, maximumInterval: 60.0, summary: \'Charge\', cancellationType: \Gplanchat\Durable\Activity\ActivityCancellationType::Abandon'), 17, self::TIP],
        ]);
    }

    public function testNamedOptionsAreMappedAndAnEmptyListDropped(): void
    {
        $this->analyse([self::DIR . 'named-options.php'], [
            [self::message('run', 'orders', ', startToClose: 30.0, attempts: 3'), 16, self::TIP],
        ]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedAtRegistration(): iterable
    {
        // The source already fails, on every run or on a call, or holds a nonRetryable entry
        // that never matches; after the move, the worker refuses to register the workflow.
        // Still reported, with the warning.
        yield 'of(0)' => ['zero-attempts.php', 'OrderActivities', ', attempts: 0|attempts: 0 is not a number of attempts'];
        yield 'an unknown nonRetryable class' => ['unknown-non-retryable.php', 'OrderActivities', ', attempts: 3, nonRetryable: [\NoSuchException::class]|nonRetryable: NoSuchException is not a \Throwable class'];
        yield 'a nonRetryable self::class' => ['self-non-retryable.php', 'OrderActivities', ', attempts: 3, nonRetryable: [self::class]|nonRetryable: unit\DurablePhpstan\Fixtures\StubCouldBeParameter\SelfNonRetryable is not a \Throwable class'];
        yield 'a contract with no #[AsActivityMethod]' => ['contract-without-activity-method.php', 'Countable', '|Countable declares no #[AsActivityMethod]'];
    }

    #[DataProvider('refusedAtRegistration')]
    public function testAMoveThatFailsAtRegistrationIsReportedWithAWarning(string $file, string $contract, string $expected): void
    {
        [$fields, $reason] = explode('|', $expected);
        $line = str_contains($file, 'contract-without') ? 15 : 16;
        $this->analyse([self::DIR . $file], [
            [self::message('run', 'orders', $fields, $contract) . \sprintf(self::REFUSED, $reason), $line, self::TIP],
        ]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function silentCases(): iterable
    {
        yield 'options computed at run time' => ['computed-options.php'];
        yield 'default(): the attribute alone would drop the retry backoff' => ['default-options.php'];
        yield 'of() with only an empty nonRetryable list' => ['empty-non-retryable.php'];
        yield 'an empty taskQueue, which the attribute refuses' => ['empty-task-queue.php'];
        yield 'a backoff coefficient the attribute refuses at registration' => ['refused-backoff.php'];
        yield 'a maximumInterval under the first retry delay, refused at registration' => ['refused-maximum-interval.php'];
        yield 'a stub a signal method reads' => ['signal-reads-stub.php'];
        yield 'a stub a helper method reads' => ['helper-reads-stub.php'];
        yield 'a stub read inside a closure' => ['closure-reads-stub.php'];
        yield 'a class with no workflow method' => ['not-a-workflow.php'];
        yield 'a workflow method an interface declares' => ['contract-interface.php'];
        yield 'a class that extends another' => ['extends-a-class.php'];
        yield 'a class that uses a trait' => ['uses-a-trait.php'];
        yield 'an activityId, which the attribute cannot carry' => ['activity-id.php'];
        yield 'a constructor that reads the stub it built' => ['constructor-reads-stub.php'];
        yield 'a local name assigned twice' => ['name-assigned-twice.php'];
        yield 'a local name that is already a parameter' => ['name-is-a-parameter.php'];
        yield 'a stub read inside an arrow function' => ['arrow-fn-reads-stub.php'];
        yield 'a property name read inside an anonymous class' => ['anonymous-class-reads-stub.php'];
        yield 'a nullsafe read in a signal method' => ['nullsafe-read-in-signal.php'];
        yield 'a dynamic read in a signal method' => ['dynamic-read-in-signal.php'];
    }

    #[DataProvider('silentCases')]
    public function testTheMoveIsNotSuggestedWhenItWouldChangeWhatRuns(string $file): void
    {
        $this->analyse([self::DIR . $file], []);
    }

    private static function message(string $method, string $name, string $fields, string $contract = 'OrderActivities'): string
    {
        return \sprintf(
            'Activity stub $%2$s could be a parameter of %1$s(): #[Activities(%4$s::class%3$s)] ActivityStub $%2$s, documented with @param ActivityStub<%4$s> $%2$s.',
            $method,
            $name,
            $fields,
            $contract,
        );
    }
}
