<?php

declare(strict_types=1);

namespace Tests\Unit\Durable;

use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Versioning\ChangePoint;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A side effect is recorded or it is not. That is a state, and a state cannot be inferred from a
 * return value: the recorded value may legitimately be `null`, `false` or `0`.
 *
 * These cases pin the guarantee `sideEffect()` exists to offer — the closure runs only once,
 * whatever it returns — and the corollary that is more easily forgotten: a journal that does not
 * grow by one event on every replay pass.
 */
final class SideEffectSlotPresenceTest extends TestCase
{
    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function valuesMistakenForAbsence(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'empty string' => [''];
        yield 'empty list' => [[]];
    }

    #[DataProvider('valuesMistakenForAbsence')]
    public function testAClosureReturningAFalsyValueRunsOnlyOnce(mixed $value): void
    {
        $store = new InMemoryEventStore();
        $calls = 0;

        $workflow = static function (WorkflowEnvironment $wf) use ($value, &$calls): mixed {
            return $wf->sideEffect(static function () use ($value, &$calls): mixed {
                ++$calls;

                return $value;
            });
        };

        self::execute($store, 'exec-1', $workflow);
        self::assertSame(1, $calls, 'the first pass runs the closure');

        self::execute($store, 'exec-1', $workflow);
        self::assertSame(1, $calls, 'the replay pass must read the result back, not recompute it');
    }

    #[DataProvider('valuesMistakenForAbsence')]
    public function testTheJournalDoesNotGrowByOneEventPerPass(mixed $value): void
    {
        $store = new InMemoryEventStore();

        $workflow = static fn(WorkflowEnvironment $wf): mixed => $wf->sideEffect(static fn(): mixed => $value);

        self::execute($store, 'exec-1', $workflow);
        self::execute($store, 'exec-1', $workflow);
        self::execute($store, 'exec-1', $workflow);

        self::assertSame(
            1,
            self::countSideEffects($store, 'exec-1'),
            'three passes over a single sideEffect() statement must leave a single event',
        );
    }

    #[DataProvider('valuesMistakenForAbsence')]
    public function testTheValueReadBackIsTheRecordedValue(mixed $value): void
    {
        $store = new InMemoryEventStore();

        $workflow = static fn(WorkflowEnvironment $wf): mixed => $wf->sideEffect(static fn(): mixed => $value);

        self::assertSame($value, self::execute($store, 'exec-1', $workflow));
        self::assertSame($value, self::execute($store, 'exec-1', $workflow), 'the replay returns the same value');
    }

    /**
     * Slots stay aligned: a "falsy" side effect must not shift the next one.
     */
    public function testAFalsySideEffectDoesNotShiftTheNextSlot(): void
    {
        $store = new InMemoryEventStore();

        $workflow = static fn(WorkflowEnvironment $wf): array => [
            'first' => $wf->sideEffect(static fn(): mixed => null),
            'second' => $wf->sideEffect(static fn(): string => 'after'),
        ];

        self::assertSame(['first' => null, 'second' => 'after'], self::execute($store, 'exec-1', $workflow));
        self::assertSame(['first' => null, 'second' => 'after'], self::execute($store, 'exec-1', $workflow));
        self::assertSame(2, self::countSideEffects($store, 'exec-1'));
    }

    private static function execute(InMemoryEventStore $store, string $executionId, \Closure $workflow): mixed
    {
        $runner = new InMemoryWorkflowRunner(
            $store,
            new InMemoryActivityTransport(),
            new RegistryActivityExecutor(),
            0,
            new WorkflowRegistry(),
        );

        return $runner->run($executionId, $workflow);
    }

    private static function countSideEffects(InMemoryEventStore $store, string $executionId): int
    {
        $total = 0;
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof SideEffectRecorded) {
                ++$total;
            }
        }

        return $total;
    }

    /**
     * The sibling caller. `version()` asks "am I replaying?" to `hasRecordedWorkAhead()`, which
     * queried the four other slot types and not side effects, for want of a way to read their
     * presence without reading their value.
     *
     * An execution whose remaining work ahead was made of side effects only was therefore seen as
     * having reached the end of its history. It took the new branch **in the middle of a replay**,
     * and wrote its version marker in the middle of a history written before the change point
     * existed — which is precisely what `version()` is there to prevent.
     */
    public function testRemainingWorkMadeOfSideEffectsKeepsTheOldVersion(): void
    {
        $store = new InMemoryEventStore();

        // The code before: two side effects, no change point.
        $before = static fn(WorkflowEnvironment $wf): array => [
            'first' => $wf->sideEffect(static fn(): mixed => null),
            'second' => $wf->sideEffect(static fn(): string => 'after'),
        ];
        self::execute($store, 'exec-1', $before);

        // The code after, on the same execution: a change point slipped in between the two side
        // effects, and the second one is still ahead.
        $after = static fn(WorkflowEnvironment $wf): array => [
            'first' => $wf->sideEffect(static fn(): mixed => null),
            // The old behaviour is still supported: the minimum is the original version.
            'version' => $wf->version('change-1', ChangePoint::DEFAULT_VERSION, 3),
            'second' => $wf->sideEffect(static fn(): string => 'after'),
        ];

        self::assertSame(
            ChangePoint::DEFAULT_VERSION,
            self::execute($store, 'exec-1', $after)['version'],
            'an in-flight execution keeps the old behaviour as long as it has journal left to replay',
        );
    }
}
