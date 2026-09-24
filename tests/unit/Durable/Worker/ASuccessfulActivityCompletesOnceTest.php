<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Worker;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskCompleted;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\RunTimeline;
use Gplanchat\Durable\Observation\WorkflowRunEventPhase;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use PHPUnit\Framework\TestCase;

/**
 * A successful activity wrote `ActivityTaskCompleted` and `ActivityCompleted` back to back with
 * the same body (#262): two timeline rows nobody could tell apart. On success the attempt's result
 * is the settled result, so one event says it. Journals written before keep the pair and must
 * still replay and still read.
 */
final class ASuccessfulActivityCompletesOnceTest extends TestCase
{
    public function testASuccessWritesOneCompletion(): void
    {
        $store = $this->processAll(static fn(): string => 'ch_1');

        self::assertSame(1, $this->eventsOfClass($store, ActivityCompleted::class));
        self::assertSame(0, $this->eventsOfClass($store, ActivityTaskCompleted::class));
    }

    public function testRetriesKeepOneFailurePerAttemptAndStillOneCompletion(): void
    {
        $calls = 0;
        $store = $this->processAll(static function () use (&$calls): string {
            if (++$calls < 3) {
                throw new \RuntimeException('boom');
            }

            return 'ch_1';
        }, new ActivityOptions(RetryLimit::ofAttempts(3), initialInterval: Duration::seconds(0.0)));

        self::assertSame(2, $this->eventsOfClass($store, ActivityTaskFailed::class), 'three attempts, two failed');
        self::assertSame(1, $this->eventsOfClass($store, ActivityCompleted::class), 'one outcome');
        self::assertSame(0, $this->eventsOfClass($store, ActivityTaskCompleted::class));
    }

    public function testAJournalWrittenBeforeStillReplays(): void
    {
        $context = new ExecutionContext(
            'exec-1',
            new EventStoreHistorySource($this->journalWrittenBefore(), 'exec-1'),
            new EventStoreCommandBuffer($this->journalWrittenBefore(), new NoopActivityTransport(), 'exec-1'),
        );

        $awaitable = $context->activity('charge', ['amount' => 10]);

        self::assertTrue($awaitable->isSettled());
        self::assertSame('ch_1', $awaitable->getResult());
    }

    public function testAJournalWrittenBeforeStillReads(): void
    {
        $history = (new JournalRunHistoryReader($this->journalWrittenBefore()))->read('exec-1');

        self::assertCount(4, $history);
        self::assertSame(WorkflowRunEventPhase::Settled, $history[2]->phase);
        self::assertSame(WorkflowRunEventPhase::Settled, $history[3]->phase);
        self::assertSame('activity:act-1', $history[3]->actionKey);
    }

    public function testTheNewShapeReadsAsRequestedStartedSettled(): void
    {
        $store = new InMemoryEventStore();
        $store->append(new ActivityScheduled('exec-1', 'act-1', 'charge', ['amount' => 10]));
        $store->append(new ActivityTaskStarted('exec-1', 'act-1', 'charge', 1));
        $store->append(new ActivityCompleted('exec-1', 'act-1', 'ch_1'));

        $history = (new JournalRunHistoryReader($store))->read('exec-1');

        self::assertSame(
            [WorkflowRunEventPhase::Requested, WorkflowRunEventPhase::Started, WorkflowRunEventPhase::Settled],
            array_map(static fn($event) => $event->phase, $history),
        );
        self::assertTrue($history[1]->started);
        self::assertSame('activity:act-1', $history[2]->actionKey);
    }

    public function testANewRunShowsTheActivitySettledOnTheTimeline(): void
    {
        $store = new InMemoryEventStore();
        $store->append(new ActivityScheduled('exec-1', 'act-1', 'charge', []));
        $this->processAll(static fn(): string => 'ch_1', store: $store);

        $actions = RunTimeline::of((new JournalRunHistoryReader($store))->read('exec-1'))->actions;

        self::assertCount(1, $actions, 'one action, no orphan row');
        self::assertCount(3, $actions[0]->events, 'requested, started, settled: no step missing');
        self::assertSame(WorkflowRunEventPhase::Settled, $actions[0]->events[2]->event->phase, 'ends settled, not running');
        self::assertCount(2, $actions[0]->segments, 'the queue, then the work');
        self::assertFalse($actions[0]->segments[1]->waiting);
    }

    private function journalWrittenBefore(): InMemoryEventStore
    {
        $store = new InMemoryEventStore();
        $store->append(new ActivityScheduled('exec-1', 'act-1', 'charge', ['amount' => 10]));
        $store->append(new ActivityTaskStarted('exec-1', 'act-1', 'charge', 1));
        $store->append(new ActivityTaskCompleted('exec-1', 'act-1', 'ch_1'));
        $store->append(new ActivityCompleted('exec-1', 'act-1', 'ch_1'));

        return $store;
    }

    private function processAll(callable $handler, ?ActivityOptions $options = null, ?InMemoryEventStore $store = null): InMemoryEventStore
    {
        $store ??= new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $executor = new RegistryActivityExecutor();
        $executor->register('charge', $handler);
        $processor = new ActivityMessageProcessor(
            $store,
            $transport,
            $executor,
            new NullWorkflowResumeDispatcher(),
            $this->createMock(ActivityHeartbeatSenderInterface::class),
        );

        $processor->process(new ActivityMessage('exec-1', 'act-1', 'charge', [], $options));
        while (null !== ($next = $transport->dequeue())) {
            $processor->process($next);
        }

        return $store;
    }

    /**
     * @param class-string $class
     */
    private function eventsOfClass(InMemoryEventStore $store, string $class): int
    {
        return \count(array_filter(
            iterator_to_array($store->readStream('exec-1'), false),
            static fn($event) => $event instanceof $class,
        ));
    }
}
