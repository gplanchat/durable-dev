<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * A condition can say what it waits for: the run list shows the label instead of where the closure
 * is written. The label is display only, it never enters the journal (#324).
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

    public function testTheLabelAddsNothingToTheJournal(): void
    {
        $unlabelled = $this->journalAfterTwoPasses(static function (WorkflowEnvironment $wf): void {
            $wf->await(static fn(): bool => false);
        });
        $labelled = $this->journalAfterTwoPasses(static function (WorkflowEnvironment $wf): void {
            $wf->await(static fn(): bool => false, label: 'signal approve');
        });

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
     * @return list<class-string> the event classes written by a start and a resume, in order
     */
    private function journalAfterTwoPasses(\Closure $workflow): array
    {
        [$engine, $store] = self::engine();
        foreach (['start', 'resume'] as $pass) {
            try {
                $engine->{$pass}('exec-1', $workflow);
            } catch (WorkflowSuspendedException) {
            }
        }

        return array_map(static fn(object $event): string => $event::class, iterator_to_array($store->readStream('exec-1'), false));
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
