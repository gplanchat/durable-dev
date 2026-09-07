<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Worker;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Failure\ActivityRetryState;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\ActivityEventJournal;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use PHPUnit\Framework\TestCase;

/**
 * The `retryState` discriminant on {@see ActivityFailed} + the trace of the intermediate attempts
 * through {@see ActivityTaskFailed}, and the "total number of attempts" semantics of maxAttempts.
 */
final class ActivityRetryStateTest extends TestCase
{
    public function testMaxAttemptsIsATotalAttemptCountAndFinalFailureIsStalled(): void
    {
        $store = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $runs = 0;
        $this->drain($store, $transport, static function () use (&$runs): never {
            ++$runs;

            throw new \RuntimeException('boom');
        }, new ActivityOptions(RetryLimit::ofAttempts(3), initialInterval: Duration::seconds(0.0)));

        // RetryLimit::ofAttempts(3) => 3 executions in total (Temporal), not 4.
        self::assertSame(3, $runs);

        $failed = $this->lastFailure($store);
        self::assertNotNull($failed);
        self::assertSame(ActivityRetryState::MaximumAttemptsReached, $failed->retryState());
        self::assertTrue($failed->isStalled());
        self::assertSame(3, $failed->failureAttempt());

        // A failed intermediate attempt leaves a trace, with its reason.
        $intermediate = $this->taskFailures($store);
        self::assertCount(3, $intermediate);
        self::assertSame(ActivityRetryState::InProgress, $intermediate[0]->retryState());
        self::assertTrue($intermediate[0]->willRetry());
        self::assertSame(ActivityRetryState::MaximumAttemptsReached, $intermediate[2]->retryState());
        self::assertSame('boom', $intermediate[0]->failureMessage());
    }

    public function testNoMaxAttemptsMeansUnlimitedRetriesLikeTemporal(): void
    {
        // Temporal semantics: a RetryPolicy with no maximum_attempts retries indefinitely.
        // The in-memory one, on the contrary, failed from the very first attempt.
        $store = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $runs = 0;
        $this->drain($store, $transport, static function () use (&$runs): never {
            ++$runs;

            throw new \RuntimeException('boom');
        }, new ActivityOptions(initialInterval: Duration::seconds(0.0)), maxDrain: 6, expectTermination: false);

        self::assertSame(7, $runs, 'no bound: every pass replays the activity');
        self::assertNull($this->lastFailure($store), 'no terminal outcome as long as it keeps retrying');
        self::assertFalse(ActivityEventJournal::hasTerminalOutcomeForActivity($store, 'exec-1', 'act-1'));
    }

    public function testBundleRetryCeilingStillBoundsAnActivityWithoutOptions(): void
    {
        // `max_activity_retries` stays a cap when the activity sets none.
        $store = new InMemoryEventStore();
        $runs = 0;
        $executor = new RegistryActivityExecutor();
        $executor->register('Boom', static function () use (&$runs): never {
            ++$runs;

            throw new \RuntimeException('boom');
        });
        $transport = new InMemoryActivityTransport();
        $processor = new ActivityMessageProcessor(
            $store,
            $transport,
            $executor,
            new NullWorkflowResumeDispatcher(),
            $this->createMock(ActivityHeartbeatSenderInterface::class),
            2,
        );

        $message = new ActivityMessage('exec-1', 'act-1', 'Boom', []);
        $processor->process($message);
        while (null !== ($next = $transport->dequeue())) {
            $processor->process($next);
        }

        self::assertSame(3, $runs, '2 retries = 3 attempts in total');
        self::assertSame(ActivityRetryState::MaximumAttemptsReached, $this->lastFailure($store)?->retryState());
    }

    public function testBackoffIsCappedAtTheTemporalDefaultInterval(): void
    {
        // With no explicit cap, an unlimited exponential backoff diverges: the Temporal default
        // is 100 x the initial interval.
        $options = new ActivityOptions(initialInterval: Duration::seconds(1.0), backoffCoefficient: 2.0);

        self::assertSame(1.0, $options->retryDelayBeforeAttempt(2)->toSeconds());
        self::assertSame(64.0, $options->retryDelayBeforeAttempt(8)->toSeconds());
        self::assertSame(100.0, $options->retryDelayBeforeAttempt(20)->toSeconds());
        self::assertSame(100.0, $options->effectiveMaximumInterval()->toSeconds());
        self::assertTrue($options->retryDelayBeforeAttempt(1)->isZero());
    }

    public function testNonRetryableExceptionStopsAtFirstAttempt(): void
    {
        $store = new InMemoryEventStore();
        $runs = 0;
        $this->drain($store, new InMemoryActivityTransport(), static function () use (&$runs): never {
            ++$runs;

            throw new \DomainException('refused');
        }, new ActivityOptions(RetryLimit::ofAttempts(5), nonRetryableExceptions: [\DomainException::class]));

        self::assertSame(1, $runs);
        $failed = $this->lastFailure($store);
        self::assertNotNull($failed);
        self::assertSame(ActivityRetryState::NonRetryableFailure, $failed->retryState());
        self::assertFalse($failed->isStalled());
    }

    public function testIntermediateFailureIsJournaledWhenALaterAttemptSucceeds(): void
    {
        $store = new InMemoryEventStore();
        $runs = 0;
        $this->drain($store, new InMemoryActivityTransport(), static function () use (&$runs): string {
            if (0 === $runs++) {
                throw new \RuntimeException('transient');
            }

            return 'ok';
        }, new ActivityOptions(RetryLimit::ofAttempts(3), initialInterval: Duration::seconds(0.0)));

        $intermediate = $this->taskFailures($store);
        self::assertCount(1, $intermediate);
        self::assertSame('transient', $intermediate[0]->failureMessage());
        self::assertSame(1, $intermediate[0]->attempt());
        self::assertNull($this->lastFailure($store));
        self::assertNotNull($this->completed($store));
    }

    public function testTransportDelegatedRetryKeepsRealExceptionAndStaysNonTerminal(): void
    {
        // Native Temporal worker: the retry belongs to the server. The journaled failure must
        // carry the real exception class (otherwise nonRetryableErrorTypes never matches)
        // and must not short-circuit the next attempt.
        $store = new InMemoryEventStore();
        $this->drain($store, new NoopActivityTransport(), static function (): never {
            throw new \DomainException('real cause');
        }, new ActivityOptions(RetryLimit::ofAttempts(5)), maxDrain: 1);

        $failed = $this->lastFailure($store);
        self::assertNotNull($failed);
        self::assertSame(\DomainException::class, $failed->failureClass());
        self::assertSame('real cause', $failed->failureMessage());
        self::assertSame(ActivityRetryState::InProgress, $failed->retryState());
        self::assertFalse(ActivityEventJournal::hasTerminalOutcomeForActivity($store, 'exec-1', 'act-1'));
    }

    public function testTransportDelegatedRetryAppliesWithoutAnyLocalRetryPolicy(): void
    {
        // Default configuration: no options, `max_activity_retries` at 0. The PHP attempt count
        // says "no more retries", but under Noop it is the Temporal server that decides — a
        // terminal failure here would stop the worker from replaying the next attempt.
        $store = new InMemoryEventStore();
        $this->drain($store, new NoopActivityTransport(), static function (): never {
            throw new \RuntimeException('transient');
        }, ActivityOptions::default(), maxDrain: 1);

        $failed = $this->lastFailure($store);
        self::assertNotNull($failed);
        self::assertSame(ActivityRetryState::InProgress, $failed->retryState());
        self::assertFalse(ActivityEventJournal::hasTerminalOutcomeForActivity($store, 'exec-1', 'act-1'));
    }

    public function testTransportDelegatedNonRetryableStaysTerminal(): void
    {
        $store = new InMemoryEventStore();
        $this->drain($store, new NoopActivityTransport(), static function (): never {
            throw new \DomainException('refused');
        }, new ActivityOptions(RetryLimit::ofAttempts(5), nonRetryableExceptions: [\DomainException::class]), maxDrain: 1);

        $failed = $this->lastFailure($store);
        self::assertNotNull($failed);
        self::assertSame(ActivityRetryState::NonRetryableFailure, $failed->retryState());
        self::assertTrue(ActivityEventJournal::hasTerminalOutcomeForActivity($store, 'exec-1', 'act-1'));
    }

    public function testSyncInMemoryDrainHonorsNonRetryableExceptions(): void
    {
        // The synchronous drain (InMemoryWorkflowRunner) ignored the ActivityOptions:
        // an exception declared non-retryable was retried there all the same.
        $store = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $executor = new RegistryActivityExecutor();
        $runs = 0;
        $executor->register('Boom', static function () use (&$runs): never {
            ++$runs;

            throw new \DomainException('refused');
        });

        $runtime = new \Gplanchat\Durable\ExecutionRuntime($store, $transport, $executor, 5);
        $options = new ActivityOptions(nonRetryableExceptions: [\DomainException::class]);
        $transport->enqueue(new ActivityMessage('exec-1', 'act-1', 'Boom', [], $options));

        $context = new \Gplanchat\Durable\ExecutionContext(
            'exec-1',
            new \Gplanchat\Durable\Store\EventStoreHistorySource($store, 'exec-1'),
            new \Gplanchat\Durable\Store\EventStoreCommandBuffer($store, $transport, 'exec-1'),
        );
        $runtime->runUntilIdle($context);

        self::assertSame(1, $runs, 'a non-retryable exception must not be retried');
        self::assertSame(
            ['ActivityTaskStarted', 'ActivityTaskFailed', 'ActivityFailed'],
            array_map(
                static fn(object $e): string => (new \ReflectionClass($e))->getShortName(),
                iterator_to_array($store->readStream('exec-1'), false),
            ),
            'the synchronous drain must produce the same trio of markers as the Messenger path',
        );
        $failed = $this->lastFailure($store);
        self::assertNotNull($failed);
        self::assertSame(ActivityRetryState::NonRetryableFailure, $failed->retryState());
    }

    // -------------------------------------------------------------------------

    private function drain(
        InMemoryEventStore $store,
        ActivityTransportInterface $transport,
        callable $handler,
        ActivityOptions $options,
        int $maxDrain = 20,
        bool $expectTermination = true,
    ): void {
        $executor = new RegistryActivityExecutor();
        $executor->register('Boom', $handler);

        $processor = new ActivityMessageProcessor(
            $store,
            $transport,
            $executor,
            new NullWorkflowResumeDispatcher(),
            $this->createMock(ActivityHeartbeatSenderInterface::class),
        );

        $message = new ActivityMessage('exec-1', 'act-1', 'Boom', [], $options);
        $processor->process($message);

        for ($i = 0; $i < $maxDrain; ++$i) {
            $next = $transport->dequeue();
            if (null === $next) {
                return;
            }
            $processor->process($next);
        }

        if ($expectTermination) {
            self::fail('Retry loop did not terminate');
        }
    }

    private function lastFailure(InMemoryEventStore $store): ?ActivityFailed
    {
        $last = null;
        foreach ($store->readStream('exec-1') as $e) {
            if ($e instanceof ActivityFailed) {
                $last = $e;
            }
        }

        return $last;
    }

    private function completed(InMemoryEventStore $store): ?ActivityCompleted
    {
        foreach ($store->readStream('exec-1') as $e) {
            if ($e instanceof ActivityCompleted) {
                return $e;
            }
        }

        return null;
    }

    /** @return list<ActivityTaskFailed> */
    private function taskFailures(InMemoryEventStore $store): array
    {
        $out = [];
        foreach ($store->readStream('exec-1') as $e) {
            if ($e instanceof ActivityTaskFailed) {
                $out[] = $e;
            }
        }

        return $out;
    }
}
