<?php

declare(strict_types=1);

namespace unit\DurablePhpstan\Fixtures;

use Carbon\Carbon;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsActivityMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface as SymfonyClockInterface;
use Symfony\Component\Clock\DatePoint;

/**
 * A fixture analysed by {@see \unit\DurablePhpstan\NondeterminismRulesTest}, never executed.
 *
 * The test reads this file: a line ending in `// reported` must carry a `durable.nondeterministic`
 * error, and no other line may. Moving a case is therefore safe, and a case cannot be added
 * without saying what it expects.
 */

/** A date class of the application: the rule has to see through the inheritance. */
final class AppDate extends \DateTimeImmutable {}

/** Not a workflow: reading the clock here is nobody's replay problem. */
final class PlainService
{
    public function whatTimeIsIt(): int
    {
        return time();
    }
}

/** An activity is the place where the clock may be read: its result is journalled. */
interface ClockActivities
{
    #[AsActivityMethod('now')]
    public function now(): int;
}

final class ClockActivitiesImplementation implements ClockActivities
{
    public function now(): int
    {
        return time(); // the stub is the only way in from a workflow
    }
}

/** Two calls between the workflow and the read: the report lands on the workflow's call. */
final class StampHelper
{
    public static function stamp(): int
    {
        return self::inner();
    }

    private static function inner(): int
    {
        return time();
    }
}

final class Nested
{
    public function outer(): int
    {
        return $this->middle();
    }

    private function middle(): int
    {
        return (int) (new \DateTime())->format('U');
    }

    public function loops(): void
    {
        $this->loops();
    }
}

final class JournalledHelper
{
    public static function stamp(WorkflowEnvironment $env): int
    {
        return $env->sideEffect(static fn(): int => time());
    }
}

#[AsWorkflow('nondeterministic')]
final class NondeterministicWorkflow
{
    public function __construct(
        private readonly ClockInterface $psrClock,
        private readonly SymfonyClockInterface $symfonyClock,
        private readonly string $given,
    ) {}

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): void {}

    public function functions(): void
    {
        time(); // reported
        microtime(true); // reported
        random_int(1, 6); // reported
        uniqid(); // reported
        sleep(1); // reported
        date('Y'); // reported
    }

    public function dateClasses(): void
    {
        new \DateTimeImmutable(); // reported
        new \DateTime('now'); // reported
        new \DateTime('tomorrow'); // reported
        new AppDate(); // reported
        new DatePoint(); // reported
        new Carbon(); // reported
        Carbon::now(); // reported
        Carbon::parse('next monday'); // reported
        Carbon::today(); // reported
    }

    public function clocks(): void
    {
        $this->psrClock->now(); // reported
        $this->symfonyClock->now(); // reported
        $this->symfonyClock->sleep(1); // reported
        Clock::get()->now(); // reported
    }

    public function deterministic(): void
    {
        new \DateTimeImmutable('2026-01-01');
        new \DateTimeImmutable('2026-01-01 10:00:00');
        new \DateTimeImmutable('@1700000000');
        new \DateTimeImmutable($this->given);
        new AppDate('2026-01-01');
        Carbon::parse('2026-01-01 10:00');
        date('Y', 1_700_000_000);
    }

    /** The value is journalled: the closure runs once, a replay reads what it returned. */
    public function journalled(WorkflowEnvironment $env): void
    {
        $env->sideEffect(static fn(): int => time());
        $env->sideEffect(static fn(): \DateTimeImmutable => new \DateTimeImmutable());
        $env->sideEffect(static fn(): int => Carbon::now()->getTimestamp());
        $env->sideEffect(static function (): float {
            return microtime(true);
        });
        time(); // reported
    }

    public function throughTheStack(): void
    {
        StampHelper::stamp(); // reported
        (new Nested())->outer(); // reported
    }

    public function whatIsJournalledStaysJournalled(WorkflowEnvironment $env): void
    {
        JournalledHelper::stamp($env);
        $env->sideEffect(static fn(): int => StampHelper::stamp());
        (new Nested())->loops();
        $this->whatIsJournalledStaysJournalled($env);
    }

    /**
     * @param ActivityStub<ClockActivities> $activities
     */
    public function throughAnActivity(#[Activities(ClockActivities::class)] ActivityStub $activities): void
    {
        $activities->now();
    }
}
