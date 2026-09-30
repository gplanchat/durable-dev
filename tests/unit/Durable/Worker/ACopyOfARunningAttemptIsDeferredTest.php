<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Worker;

use Gplanchat\Durable\Exception\ActivityAttemptDeferred;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\ActivityAttemptClaimInterface;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Two copies of one attempt delivered at the same moment both passed the journal guards and both
 * ran. Temporal refuses the second start server-side; here a claim per (execution, activity,
 * attempt) stands in for it (#590).
 *
 * The refusal means "not now", not "never": a holder that died keeps its claim until the lock TTL,
 * and a copy dropped meanwhile would lose the attempt for good. The copy is handed back to its host
 * to be retried later.
 */
final class ACopyOfARunningAttemptIsDeferredTest extends TestCase
{
    public function testACopyWhoseAttemptIsClaimedElsewhereIsDeferred(): void
    {
        $runs = 0;
        $store = new InMemoryEventStore();
        $processor = $this->processor($store, $runs, new class implements ActivityAttemptClaimInterface {
            public function claim(ExecutionId $executionId, string $activityId, int $attempt): ?\Closure
            {
                return null;
            }
        });

        try {
            $processor->process(new ActivityMessage('exec-1', 'act-1', 'charge', []));
            self::fail('the copy must go back to its host, to be retried later');
        } catch (ActivityAttemptDeferred $deferred) {
            self::assertSame(['exec-1', 'act-1', 1], [$deferred->executionId, $deferred->activityId, $deferred->attempt]);
        }

        self::assertSame(0, $runs, 'the worker holding the attempt runs it');
        self::assertSame([], iterator_to_array($store->readStream(ExecutionId::fromString('exec-1')), false), 'and journals it');
    }

    public function testTheClaimIsReleasedEvenWhenTheMessageFails(): void
    {
        $runs = 0;
        $claims = new class implements ActivityAttemptClaimInterface {
            /** @var list<string> */
            public array $log = [];

            public function claim(ExecutionId $executionId, string $activityId, int $attempt): \Closure
            {
                $this->log[] = "claim {$activityId}#{$attempt}";

                return function () use ($activityId, $attempt): void {
                    $this->log[] = "release {$activityId}#{$attempt}";
                };
            }
        };
        $resumes = $this->createStub(WorkflowResumeDispatcher::class);
        $resumes->method('dispatchResume')->willThrowException(new \RuntimeException('broker down'));
        $processor = $this->processor(new InMemoryEventStore(), $runs, $claims, $resumes);

        try {
            $processor->process(new ActivityMessage('exec-1', 'act-1', 'charge', []));
            self::fail('the broker error must reach the transport');
        } catch (\RuntimeException) {
        }

        self::assertSame(['claim act-1#1', 'release act-1#1'], $claims->log);
        self::assertSame(1, $runs);
    }

    private function processor(InMemoryEventStore $store, int &$runs, ActivityAttemptClaimInterface $claims, ?WorkflowResumeDispatcher $resumes = null): ActivityMessageProcessor
    {
        $executor = new RegistryActivityExecutor();
        $executor->register('charge', static function () use (&$runs): string {
            ++$runs;

            return 'ch_1';
        });

        return new ActivityMessageProcessor(
            $store,
            new InMemoryActivityTransport(),
            $executor,
            $resumes ?? $this->createStub(WorkflowResumeDispatcher::class),
            $this->createStub(ActivityHeartbeatSenderInterface::class),
            attemptClaim: $claims,
        );
    }
}
