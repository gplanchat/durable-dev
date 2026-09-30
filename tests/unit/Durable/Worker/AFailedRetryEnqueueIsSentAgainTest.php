<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Worker;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use PHPUnit\Framework\TestCase;

/**
 * #590: the failure of attempt N is journalled, then N+1 is queued. When the queueing fails, the
 * redelivery of N met the #319 guard and returned: N+1 was never queued, and the run waited forever.
 *
 * Temporal's model (the user, 2026-09-28): the server keeps the next attempt's dispatch task until it
 * is delivered. Here `ActivityRetryQueued` is that record; a redelivery queues the retry only when
 * the failure is journalled without it.
 */
final class AFailedRetryEnqueueIsSentAgainTest extends TestCase
{
    public function testTheRedeliveryQueuesTheAttemptTheBrokerRefused(): void
    {
        $runs = 0;
        [$store, $transport, $processor] = $this->processor($runs, refusals: 1);
        $message = new ActivityMessage('exec-1', 'act-1', 'charge', []);

        try {
            $processor->process($message);
            self::fail('the broker error must reach the transport, which redelivers the message');
        } catch (\RuntimeException $e) {
            self::assertSame('broker down', $e->getMessage());
        }
        $processor->process($message);

        $next = $transport->dequeue();
        self::assertNotNull($next, 'the redelivery queues the next attempt');
        self::assertSame(2, $next->attempt);
        self::assertNull($transport->dequeue(), 'once');
        self::assertSame(1, $runs, 'without running the failed attempt again');
    }

    /**
     * The retry was queued, then the message came back for another reason: the journal records the
     * dispatch, so the next attempt is not queued twice, even before it starts.
     */
    public function testARedeliveryAfterTheRetryWasQueuedSendsNothing(): void
    {
        $runs = 0;
        [, $transport, $processor] = $this->processor($runs, refusals: 0);
        $message = new ActivityMessage('exec-1', 'act-1', 'charge', []);

        $processor->process($message);
        $processor->process($message);

        self::assertSame(2, $transport->dequeue()?->attempt);
        self::assertNull($transport->dequeue(), 'attempt 2 is queued once');
        self::assertSame(1, $runs);
    }

    /**
     * @return array{InMemoryEventStore, InMemoryActivityTransport, ActivityMessageProcessor}
     */
    private function processor(int &$runs, int $refusals): array
    {
        $store = new InMemoryEventStore();
        $queue = new InMemoryActivityTransport();
        $refusing = new class ($queue, $refusals) implements ActivityTransportInterface {
            public function __construct(private readonly InMemoryActivityTransport $inner, private int $refusals) {}

            public function enqueue(ActivityMessage $message): void
            {
                if ($this->refusals-- > 0) {
                    throw new \RuntimeException('broker down');
                }
                $this->inner->enqueue($message);
            }

            public function dequeue(): ?ActivityMessage
            {
                return $this->inner->dequeue();
            }

            public function isEmpty(): bool
            {
                return $this->inner->isEmpty();
            }

            public function nextDueAt(): ?float
            {
                return $this->inner->nextDueAt();
            }

            public function removePendingFor(ExecutionId $executionId, string $activityId): bool
            {
                return $this->inner->removePendingFor($executionId, $activityId);
            }
        };
        $executor = new RegistryActivityExecutor();
        $executor->register('charge', static function () use (&$runs): never {
            ++$runs;

            throw new \RuntimeException('card declined');
        });
        $processor = new ActivityMessageProcessor(
            $store,
            $refusing,
            $executor,
            $this->createStub(WorkflowResumeDispatcher::class),
            $this->createStub(ActivityHeartbeatSenderInterface::class),
        );

        return [$store, $queue, $processor];
    }
}
