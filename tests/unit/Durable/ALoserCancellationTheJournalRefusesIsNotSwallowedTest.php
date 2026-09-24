<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * C-6 (#329): the condition wins the race, the deadline timer loses, and cancelling it goes through
 * the journal. When the journal refuses, that is not a branch failure: the run used to complete
 * as if the cancellation had been written.
 */
final class ALoserCancellationTheJournalRefusesIsNotSwallowedTest extends TestCase
{
    public function testTheJournalErrorReachesTheCaller(): void
    {
        $inner = new InMemoryEventStore();
        $store = new class ($inner) implements EventStoreInterface {
            public function __construct(private readonly InMemoryEventStore $inner) {}

            public function append(Event $event): void
            {
                if ($event instanceof TimerCancelled) {
                    throw new \RuntimeException('journal unavailable');
                }
                $this->inner->append($event);
            }

            public function readStream(string $executionId): iterable
            {
                return $this->inner->readStream($executionId);
            }

            public function readStreamWithRecordedAt(string $executionId): iterable
            {
                return $this->inner->readStreamWithRecordedAt($executionId);
            }

            public function countEventsInStream(string $executionId): int
            {
                return $this->inner->countEventsInStream($executionId);
            }
        };
        $inner->append(new ExecutionStarted('race-1', []));
        $inner->append(new TimerScheduled('race-1', 'timer-a', 9999999999.0));
        $inner->append(new WorkflowSignalReceived('race-1', 'tick', ['n' => 1]));

        $engine = new ExecutionEngine($store, new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true));

        $this->expectExceptionMessage('journal unavailable');
        $engine->resume('race-1', static function (WorkflowEnvironment $wf): string {
            $ticks = [];
            $wf->onSignal('tick', static function (array $payload) use (&$ticks): void {
                $ticks[] = $payload;
            });
            $wf->await(static function () use (&$ticks): bool {
                return [] !== $ticks;
            }, Duration::seconds(30));

            return 'satisfied';
        });
    }
}
