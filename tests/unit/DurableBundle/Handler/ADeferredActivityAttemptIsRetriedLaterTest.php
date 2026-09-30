<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Bundle\Handler;

use Gplanchat\Durable\Bundle\Handler\ActivityRunHandler;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\ActivityAttemptClaimInterface;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * An attempt claimed elsewhere is "not now": Messenger retries a recoverable failure whatever
 * `max_retries` says, so a copy whose holder died runs once the claim expires (#590).
 */
final class ADeferredActivityAttemptIsRetriedLaterTest extends TestCase
{
    public function testMessengerRetriesTheCopyLater(): void
    {
        $handler = new ActivityRunHandler(new ActivityMessageProcessor(
            new InMemoryEventStore(),
            new InMemoryActivityTransport(),
            new RegistryActivityExecutor(),
            new NullWorkflowResumeDispatcher(),
            $this->createStub(ActivityHeartbeatSenderInterface::class),
            attemptClaim: new class implements ActivityAttemptClaimInterface {
                public function claim(ExecutionId $executionId, string $activityId, int $attempt): ?\Closure
                {
                    return null;
                }
            },
        ));

        try {
            $handler(new ActivityMessage('exec-1', 'act-1', 'charge', []));
            self::fail('the copy must be retried, not acknowledged');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertInstanceOf(\Gplanchat\Durable\Exception\ActivityAttemptDeferred::class, $e->getPrevious());
        }
    }
}
