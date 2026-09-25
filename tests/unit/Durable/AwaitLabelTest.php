<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * A condition can say what it waits for: the run list shows the label instead of where the closure
 * is written. Nothing records the label while the run waits (#324).
 *
 * @internal
 */
final class AwaitLabelTest extends TestCase
{
    public function testALabelledConditionIsDescribedByItsLabel(): void
    {
        self::assertSame('signal approve', $this->waitingOn(static function (WorkflowEnvironment $wf): void {
            $wf->await(static fn(): bool => false, label: 'signal approve');
        }));
    }

    public function testAnUnlabelledConditionStillSaysWhereItIsWritten(): void
    {
        self::assertStringStartsWith('condition at ' . __FILE__ . ':', (string) $this->waitingOn(static function (WorkflowEnvironment $wf): void {
            $wf->await(static fn(): bool => false);
        }));
    }

    public function testALabelOnAnAwaitableThatNamesItselfIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already names itself');

        $this->waitingOn(static function (WorkflowEnvironment $wf): void {
            $wf->await($wf->timer(60), label: 'a minute');
        });
    }

    public function testAnEmptyLabelIsNoLabelOnEitherPath(): void
    {
        // No label on a condition, so no refusal on a timer either: one rule for ''.
        self::assertStringStartsWith('condition at ' . __FILE__ . ':', (string) $this->waitingOn(static function (WorkflowEnvironment $wf): void {
            $wf->await(static fn(): bool => false, label: '');
        }));
        self::assertNotNull($this->waitingOn(static function (WorkflowEnvironment $wf): void {
            $wf->await($wf->timer(60), label: '');
        }));
    }

    /**
     * While it waits, a labelled condition writes the same rows as an unlabelled one: the label goes
     * into no event, not even the deadline's timer. Only the timer id (a fresh UUID) and its due date
     * (the clock) differ from run to run, so those two are masked.
     */
    public function testWhileItWaitsTheLabelAddsNothingToTheJournal(): void
    {
        $unlabelled = $this->rowsAfterTwoPasses(null);
        $labelled = $this->rowsAfterTwoPasses('signal approve');

        self::assertCount(2, $labelled, 'the start and the deadline timer');
        self::assertSame($unlabelled, $labelled);
    }

    private function waitingOn(\Closure $workflow): ?string
    {
        [$engine] = self::engine();

        try {
            $engine->start('exec-1', $workflow);
        } catch (WorkflowSuspendedException $suspended) {
            return $suspended->waitingOn();
        }
        self::fail('The workflow should have suspended.');
    }

    /**
     * @return list<array<string, mixed>> the rows a start and a resume write, run-specific values masked
     */
    private function rowsAfterTwoPasses(?string $label): array
    {
        [$engine, $store] = self::engine();
        $workflow = static function (WorkflowEnvironment $wf) use ($label): void {
            $wf->await(static fn(): bool => false, Duration::hours(1), label: $label);
        };
        foreach (['start', 'resume'] as $pass) {
            try {
                $engine->{$pass}('exec-1', $workflow);
            } catch (WorkflowSuspendedException) {
            }
        }

        return array_map(static function (Event $event): array {
            $row = EventDataMapper::fromDomainEvent($event);
            foreach (['timerId', 'scheduledAt'] as $runSpecific) {
                if (\array_key_exists($runSpecific, $row['payload'])) {
                    $row['payload'][$runSpecific] = '(masked)';
                }
            }

            return $row;
        }, iterator_to_array($store->readStream('exec-1'), false));
    }

    /**
     * @return array{ExecutionEngine, InMemoryEventStore}
     */
    private static function engine(): array
    {
        $store = new InMemoryEventStore();

        return [new ExecutionEngine($store, new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true)), $store];
    }
}
