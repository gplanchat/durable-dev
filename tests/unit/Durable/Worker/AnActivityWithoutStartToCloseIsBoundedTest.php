<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Worker;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\ExecutionId;
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
 * Temporal bounds an attempt at 30 s when the activity sets no start-to-close; the journal
 * backends apply the same default, checked after the attempt returns (#1050).
 */
final class AnActivityWithoutStartToCloseIsBoundedTest extends TestCase
{
    public function testTheDefaultBoundIsThirtySeconds(): void
    {
        self::assertSame(30.0, (new ActivityTimeouts())->attemptBound()->toSeconds());
    }

    public function testStartToCloseWinsOverTheDefault(): void
    {
        self::assertSame(2.0, ActivityTimeouts::attempt(Duration::seconds(2))->attemptBound()->toSeconds());
    }

    public function testScheduleToCloseStandsInForAnAbsentStartToClose(): void
    {
        $timeouts = new ActivityTimeouts(scheduleToClose: Duration::seconds(90));

        self::assertSame(90.0, $timeouts->attemptBound()->toSeconds());
    }

    public function testAnAttemptPastAnExplicitStartToCloseFails(): void
    {
        $options = new ActivityOptions(retryLimit: RetryLimit::ofAttempts(1), timeouts: ActivityTimeouts::attempt(Duration::seconds(0.001)));

        self::assertSame('Activity start-to-close timeout exceeded.', $this->lastFailure($options, sleepMicroseconds: 20_000));
    }

    public function testAnAttemptPastScheduleToCloseAloneFails(): void
    {
        $options = new ActivityOptions(
            retryLimit: RetryLimit::ofAttempts(1),
            timeouts: new ActivityTimeouts(scheduleToClose: Duration::seconds(0.001)),
        );

        self::assertSame('Activity start-to-close timeout exceeded.', $this->lastFailure($options, sleepMicroseconds: 20_000));
    }

    public function testAnAttemptWithoutOptionsInsideTheDefaultSucceeds(): void
    {
        self::assertNull($this->lastFailure(null, sleepMicroseconds: 0));
    }

    private function lastFailure(?ActivityOptions $options, int $sleepMicroseconds): ?string
    {
        $clock = new FrozenClock(1000.0);
        $store = new InMemoryEventStore();
        $executor = new RegistryActivityExecutor();
        $executor->register('charge', static function () use ($sleepMicroseconds): string {
            usleep($sleepMicroseconds);

            return 'ok';
        });
        $processor = new ActivityMessageProcessor(
            $store,
            new InMemoryActivityTransport($clock),
            $executor,
            new NullWorkflowResumeDispatcher(),
            $this->createStub(ActivityHeartbeatSenderInterface::class),
            maxRetries: 0,
            clock: $clock,
        );

        $processor->process(new ActivityMessage('exec-1', 'act-1', 'charge', [], $options, 1, 1000.0));

        $last = null;
        foreach ($store->readStream(ExecutionId::fromString('exec-1')) as $event) {
            $last = $event;
        }

        return $last instanceof ActivityFailed ? $last->failureMessage() : null;
    }
}
