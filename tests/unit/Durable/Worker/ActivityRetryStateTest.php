<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Worker;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Failure\ActivityRetryState;
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
 * Discriminant `retryState` sur {@see ActivityFailed} + trace des tentatives intermédiaires
 * via {@see ActivityTaskFailed}, et sémantique « nombre total de tentatives » de maxAttempts.
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
        }, new ActivityOptions(maxAttempts: 3, initialIntervalSeconds: 0.0));

        // maxAttempts: 3 => 3 exécutions au total (Temporal), pas 4.
        self::assertSame(3, $runs);

        $failed = $this->lastFailure($store);
        self::assertNotNull($failed);
        self::assertSame(ActivityRetryState::MaximumAttemptsReached, $failed->retryState());
        self::assertTrue($failed->isStalled());
        self::assertSame(3, $failed->failureAttempt());

        // Une tentative intermédiaire échouée laisse une trace, avec sa raison.
        $intermediate = $this->taskFailures($store);
        self::assertCount(3, $intermediate);
        self::assertSame(ActivityRetryState::InProgress, $intermediate[0]->retryState());
        self::assertTrue($intermediate[0]->willRetry());
        self::assertSame(ActivityRetryState::MaximumAttemptsReached, $intermediate[2]->retryState());
        self::assertSame('boom', $intermediate[0]->failureMessage());
    }

    public function testNonRetryableExceptionStopsAtFirstAttempt(): void
    {
        $store = new InMemoryEventStore();
        $runs = 0;
        $this->drain($store, new InMemoryActivityTransport(), static function () use (&$runs): never {
            ++$runs;
            throw new \DomainException('refused');
        }, new ActivityOptions(maxAttempts: 5, nonRetryableExceptions: [\DomainException::class]));

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
        }, new ActivityOptions(maxAttempts: 3, initialIntervalSeconds: 0.0));

        $intermediate = $this->taskFailures($store);
        self::assertCount(1, $intermediate);
        self::assertSame('transient', $intermediate[0]->failureMessage());
        self::assertSame(1, $intermediate[0]->attempt());
        self::assertNull($this->lastFailure($store));
        self::assertNotNull($this->completed($store));
    }

    public function testTransportDelegatedRetryKeepsRealExceptionAndStaysNonTerminal(): void
    {
        // Worker Temporal natif : le retry appartient au serveur. L'échec journalisé doit
        // porter la vraie classe d'exception (sinon nonRetryableErrorTypes ne matche jamais)
        // et ne doit pas court-circuiter la tentative suivante.
        $store = new InMemoryEventStore();
        $this->drain($store, new NoopActivityTransport(), static function (): never {
            throw new \DomainException('real cause');
        }, new ActivityOptions(maxAttempts: 5), maxDrain: 1);

        $failed = $this->lastFailure($store);
        self::assertNotNull($failed);
        self::assertSame(\DomainException::class, $failed->failureClass());
        self::assertSame('real cause', $failed->failureMessage());
        self::assertSame(ActivityRetryState::InProgress, $failed->retryState());
        self::assertFalse(ActivityEventJournal::hasTerminalOutcomeForActivity($store, 'exec-1', 'act-1'));
    }

    // -------------------------------------------------------------------------

    private function drain(
        InMemoryEventStore $store,
        ActivityTransportInterface $transport,
        callable $handler,
        ActivityOptions $options,
        int $maxDrain = 20,
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

        $message = new ActivityMessage('exec-1', 'act-1', 'Boom', [], $options->toMetadata());
        $processor->process($message);

        for ($i = 0; $i < $maxDrain; ++$i) {
            $next = $transport->dequeue();
            if (null === $next) {
                return;
            }
            $processor->process($next);
        }

        self::fail('Retry loop did not terminate');
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
