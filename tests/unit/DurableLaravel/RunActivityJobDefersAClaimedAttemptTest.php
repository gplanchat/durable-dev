<?php

declare(strict_types=1);

namespace unit\DurableLaravel;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Laravel\Queue\RunActivityJob;
use Gplanchat\Durable\Port\ActivityAttemptClaimInterface;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use PHPUnit\Framework\TestCase;

/**
 * An attempt claimed elsewhere is "not now": the job puts the same attempt back on the queue for
 * later, rather than letting Laravel count it against `tries` or drop it (#590).
 */
final class RunActivityJobDefersAClaimedAttemptTest extends TestCase
{
    public function testTheSameAttemptGoesBackOnTheQueueLater(): void
    {
        $queue = new InMemoryActivityTransport();
        $processor = new ActivityMessageProcessor(
            new InMemoryEventStore(),
            $queue,
            new RegistryActivityExecutor(),
            new NullWorkflowResumeDispatcher(),
            $this->createStub(ActivityHeartbeatSenderInterface::class),
            attemptClaim: new class implements ActivityAttemptClaimInterface {
                public function claim(ExecutionId $executionId, string $activityId, int $attempt): ?\Closure
                {
                    return null;
                }
            },
        );

        (new RunActivityJob(new ActivityMessage('exec-1', 'act-1', 'charge', [], attempt: 3)))->handle($processor, $queue);

        self::assertNull($queue->dequeue(), 'not due yet');
        self::assertGreaterThan(microtime(true) + 9.0, $queue->nextDueAt() ?? 0.0, 'but queued about ten seconds out');
    }
}
