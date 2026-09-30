<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\NexusOperationCancelled;
use Gplanchat\Durable\Event\NexusOperationCompleted;
use Gplanchat\Durable\Event\NexusOperationFailed;
use Gplanchat\Durable\Event\NexusOperationScheduled;
use Gplanchat\Durable\Event\NexusOperationTimedOut;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\NexusOperationState;
use Gplanchat\Durable\Observation\NexusOperationSummary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A dashboard shows where a run waits, and a Nexus operation is the wait served by someone else
 * (#670). The summary says, per operation, where it is served and whether it is settled.
 */
final class NexusOperationsOfARunTest extends TestCase
{
    public function testAnOperationWithNoOutcomeIsInFlightNotFailed(): void
    {
        $operations = NexusOperationSummary::of([
            new ExecutionStarted(ExecutionId::fromString('exec-1'), []),
            new NexusOperationScheduled(ExecutionId::fromString('exec-1'), 5, 'demo-shop-stock', 'stock', 'reserve'),
        ]);

        self::assertEquals([new NexusOperationSummary('demo-shop-stock', 'stock', 'reserve', NexusOperationState::InFlight)], $operations);
        self::assertSame('in flight', $operations[0]->state->label());
    }

    /**
     * @return iterable<string, array{\Closure(int): Event, NexusOperationState, string}>
     */
    public static function outcomes(): iterable
    {
        yield 'completed' => [static fn(int $id): Event => new NexusOperationCompleted(ExecutionId::fromString('exec-1'), $id), NexusOperationState::Completed, 'completed'];
        yield 'failed' => [static fn(int $id): Event => new NexusOperationFailed(ExecutionId::fromString('exec-1'), $id), NexusOperationState::Failed, 'failed'];
        yield 'timed out' => [static fn(int $id): Event => new NexusOperationTimedOut(ExecutionId::fromString('exec-1'), $id), NexusOperationState::TimedOut, 'timed out'];
        yield 'cancelled' => [static fn(int $id): Event => new NexusOperationCancelled(ExecutionId::fromString('exec-1'), $id), NexusOperationState::Cancelled, 'cancelled'];
    }

    /**
     * @param \Closure(int): Event $outcome
     */
    #[DataProvider('outcomes')]
    public function testAnOutcomeSettlesItsOwnOperationOnly(\Closure $outcome, NexusOperationState $state, string $label): void
    {
        $operations = NexusOperationSummary::of([
            new NexusOperationScheduled(ExecutionId::fromString('exec-1'), 5, 'demo-business-billing', 'billing', 'verify'),
            new NexusOperationScheduled(ExecutionId::fromString('exec-1'), 9, 'demo-business-billing', 'billing', 'charge'),
            $outcome(5),
        ]);

        self::assertSame([$state, NexusOperationState::InFlight], [$operations[0]->state, $operations[1]->state]);
        self::assertSame([$label, 'in flight'], [$operations[0]->state->label(), $operations[1]->state->label()]);
        self::assertSame(['verify', 'charge'], [$operations[0]->operation, $operations[1]->operation]);
    }

    public function testARunWithoutNexusHasNoOperations(): void
    {
        self::assertSame([], NexusOperationSummary::of([new ExecutionStarted(ExecutionId::fromString('exec-1'), [])]));
    }
}
