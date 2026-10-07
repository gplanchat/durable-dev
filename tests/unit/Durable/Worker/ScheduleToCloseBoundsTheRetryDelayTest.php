<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Worker;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Failure\ActivityRetryState;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\FrozenClock;

/**
 * Temporal stops retrying when the backoff would run past schedule-to-close, and records the
 * attempt's own failure with the timeout retry state (#978). The pickup checks stay.
 */
final class ScheduleToCloseBoundsTheRetryDelayTest extends TestCase
{
    public function testARetryThatCannotStartWithinTheBudgetIsNotQueued(): void
    {
        [$store, $transport, $processor] = $this->processor(new FrozenClock(1000.0), scheduleToClose: 5.0, delay: 10.0);

        $processor->process(new ActivityMessage('exec-1', 'act-1', 'charge', [], $this->options(5.0, 10.0), 1, 1000.0));

        self::assertNull($transport->nextDueAt(), 'attempt 2 would start at +10 s, past the 5 s budget');
        $failures = array_values(array_filter(
            iterator_to_array($store->readStream(ExecutionId::fromString('exec-1')), false),
            static fn($e): bool => $e instanceof ActivityFailed || $e instanceof ActivityTaskFailed,
        ));
        self::assertCount(2, $failures);
        self::assertInstanceOf(ActivityTaskFailed::class, $failures[0]);
        self::assertSame(ActivityRetryState::Timeout, $failures[0]->retryState());
        self::assertInstanceOf(ActivityFailed::class, $failures[1]);
        self::assertSame(ActivityRetryState::Timeout, $failures[1]->retryState());
        self::assertStringContainsString('card declined', $failures[1]->failureMessage());
    }

    public function testARetryThatFitsTheBudgetIsQueued(): void
    {
        [, $transport, $processor] = $this->processor(new FrozenClock(1000.0), scheduleToClose: 60.0, delay: 10.0);

        $processor->process(new ActivityMessage('exec-1', 'act-1', 'charge', [], $this->options(60.0, 10.0), 1, 1000.0));

        self::assertNotNull($transport->nextDueAt(), 'attempt 2 is queued');
    }

    public function testTheTimeTheAttemptTookCountsTowardsTheBudget(): void
    {
        $clock = new FrozenClock(1000.0);
        [, $transport, $processor] = $this->processor($clock, scheduleToClose: 5.0, delay: 1.0, attemptSeconds: 4.5);

        $processor->process(new ActivityMessage('exec-1', 'act-1', 'charge', [], $this->options(5.0, 1.0), 1, 1000.0));

        self::assertNull($transport->nextDueAt(), 'the attempt ran 4.5 s, the 1 s backoff ends at 5.5 s');
    }

    public function testTheClockPastTheBudgetAtPickupFailsWithTheTemporalMessage(): void
    {
        [$store, , $processor] = $this->processor(new FrozenClock(1100.0), scheduleToClose: 5.0, delay: 1.0);

        $processor->process(new ActivityMessage('exec-1', 'act-1', 'charge', [], $this->options(5.0, 1.0), 1, 1000.0));

        $last = null;
        foreach ($store->readStream(ExecutionId::fromString('exec-1')) as $event) {
            $last = $event;
        }
        self::assertInstanceOf(ActivityFailed::class, $last);
        self::assertSame('Activity schedule-to-close timeout exceeded.', $last->failureMessage());
    }

    public function testTheClockPastScheduleToStartAtPickupFailsWithTheTemporalMessage(): void
    {
        [$store, , $processor] = $this->processor(new FrozenClock(1100.0), scheduleToClose: 5.0, delay: 1.0);
        $options = new ActivityOptions(timeouts: new ActivityTimeouts(scheduleToStart: Duration::seconds(5)));

        $processor->process(new ActivityMessage('exec-1', 'act-1', 'charge', [], $options, 1, 1000.0));

        $last = null;
        foreach ($store->readStream(ExecutionId::fromString('exec-1')) as $event) {
            $last = $event;
        }
        self::assertInstanceOf(ActivityFailed::class, $last);
        self::assertSame('Activity schedule-to-start timeout exceeded.', $last->failureMessage());
    }

    private function options(float $scheduleToClose, float $delay): ActivityOptions
    {
        return new ActivityOptions(
            retryLimit: RetryLimit::ofAttempts(5),
            initialInterval: Duration::seconds($delay),
            backoffCoefficient: 1.0,
            timeouts: new ActivityTimeouts(scheduleToClose: Duration::seconds($scheduleToClose)),
        );
    }

    /**
     * @return array{InMemoryEventStore, InMemoryActivityTransport, ActivityMessageProcessor}
     */
    private function processor(FrozenClock $clock, float $scheduleToClose, float $delay, float $attemptSeconds = 0.0): array
    {
        $store = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport($clock);
        $executor = new RegistryActivityExecutor();
        $executor->register('charge', static function () use ($clock, $attemptSeconds): never {
            $clock->advance($attemptSeconds);

            throw new \RuntimeException('card declined');
        });

        return [$store, $transport, new ActivityMessageProcessor(
            $store,
            $transport,
            $executor,
            new NullWorkflowResumeDispatcher(),
            $this->createStub(ActivityHeartbeatSenderInterface::class),
            clock: $clock,
        )];
    }
}
