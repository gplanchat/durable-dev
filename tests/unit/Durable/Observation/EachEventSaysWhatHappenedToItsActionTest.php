<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\WorkflowRunEventPhase;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The rows of one action used to read identically: the label names the thing, the kind its nature,
 * and nothing said what happened to it (#261). The phase does, and the attempt says which try.
 */
final class EachEventSaysWhatHappenedToItsActionTest extends TestCase
{
    public function testTheRowsOfAnActivityAreRequestedStartedFailedStartedSettled(): void
    {
        $store = new InMemoryEventStore();
        foreach ([
            new ExecutionStarted('exec-1', []),
            new ActivityScheduled('exec-1', 'act-1', 'charge', []),
            new ActivityTaskStarted('exec-1', 'act-1', 'charge', 1),
            new ActivityTaskFailed('exec-1', 'act-1', 'charge', 1, \RuntimeException::class, 'declined'),
            new ActivityTaskStarted('exec-1', 'act-1', 'charge', 2),
            new ActivityCompleted('exec-1', 'act-1', 'ch_1'),
            new TimerScheduled('exec-1', 'tim-1', 1790244000.0),
            new TimerCompleted('exec-1', 'tim-1'),
            new WorkflowSignalReceived('exec-1', 'approve', []),
        ] as $event) {
            $store->append($event);
        }

        $history = (new JournalRunHistoryReader($store))->read('exec-1');

        self::assertSame(
            ['started', 'requested', 'started', 'failed', 'started', 'settled', 'requested', 'settled', null],
            array_map(static fn($event): ?string => $event->phase?->value, $history),
        );
        self::assertSame([null, null, 1, 1, 2, null, null, null, null], array_column($history, 'attempt'));
        self::assertSame(WorkflowRunEventPhase::Failed, $history[3]->phase);
    }
}
