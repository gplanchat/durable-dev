<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\ActivityRetryQueued;
use Gplanchat\Durable\Exception\ResumeArrivedBeforeItsOutcome;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CoreResumeWithoutASymfonyBusTest.php';

/**
 * DUR050 (#605) and #590 meet in the activity processor: a resume may arrive before the outcome it
 * announces, and a failed attempt's retry may have to be queued again. A failure that will retry is
 * not an outcome: an early resume must keep waiting through the queued retry, and proceed only once
 * the next attempt has journalled its outcome.
 */
final class AnEarlyResumeWhileARetryIsQueuedTest extends TestCase
{
    public function testTheEarlyResumeWaitsThroughTheRetryAndProceedsOnItsOutcome(): void
    {
        $store = new InMemoryEventStore();
        $queue = new InMemoryActivityTransport();
        $announced = [];
        $attempts = 0;
        $executor = new RegistryActivityExecutor();
        $executor->register('charge', static function () use (&$attempts): string {
            if (1 === ++$attempts) {
                throw new \RuntimeException('card declined');
            }

            return 'ch_1';
        });
        $processor = new ActivityMessageProcessor(
            $store,
            $this->refusingOnce($queue),
            $executor,
            $this->recordingAnnouncements($announced),
            $this->createStub(ActivityHeartbeatSenderInterface::class),
        );
        $handler = $this->resumeHandler($store);
        $attempt1 = new ActivityMessage('exec-1', 'act-1', 'charge', []);

        // Attempt 1 fails; the broker refuses its retry, and the redelivery queues it (#590).
        try {
            $processor->process($attempt1);
        } catch (\RuntimeException) {
        }
        $processor->process($attempt1);
        self::assertSame([2], $this->queuedRetries($store), 'the retry is recorded as queued, once');

        // A resume announcing act-1 arrives while attempt 2 is queued: a failure that will retry
        // is not the outcome it waits for (DUR050).
        try {
            $handler(new ResumeWorkflowMessage('exec-1', [], AwaitedFact::activity('act-1')));
            self::fail('the early resume must keep waiting');
        } catch (ResumeArrivedBeforeItsOutcome $e) {
            self::assertSame(['act-1'], $e->awaited->ids);
        }
        self::assertSame([], $announced, 'a retry announces nothing: no outcome yet');

        // Attempt 2 succeeds: it announces its outcome before appending it, and the waiting resume,
        // retried by its transport, now proceeds.
        $attempt2 = $queue->dequeue();
        self::assertSame(2, $attempt2?->attempt);
        $processor->process($attempt2);
        self::assertSame([AwaitedFact::activity('act-1')->describe()], $announced);

        $handler(new ResumeWorkflowMessage('exec-1', [], AwaitedFact::activity('act-1')));
        self::assertSame(2, $attempts, 'each attempt ran once');
    }

    /**
     * @return list<int>
     */
    private function queuedRetries(InMemoryEventStore $store): array
    {
        $attempts = [];
        foreach ($store->readStream(ExecutionId::fromString('exec-1')) as $event) {
            if ($event instanceof ActivityRetryQueued) {
                $attempts[] = $event->attempt();
            }
        }

        return $attempts;
    }

    /**
     * @param list<string> $announced
     */
    private function recordingAnnouncements(array &$announced): WorkflowResumeDispatcher
    {
        return new class ($announced) implements WorkflowResumeDispatcher {
            /** @param list<string> $announced */
            public function __construct(private array &$announced) {}

            public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void {}

            public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void
            {
                $this->announced[] = $fact->describe();
            }

            public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void {}
        };
    }

    private function refusingOnce(InMemoryActivityTransport $queue): ActivityTransportInterface
    {
        return new class ($queue) implements ActivityTransportInterface {
            private bool $refused = false;

            public function __construct(private readonly InMemoryActivityTransport $inner) {}

            public function enqueue(ActivityMessage $message): void
            {
                if (!$this->refused) {
                    $this->refused = true;

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
    }

    private function resumeHandler(InMemoryEventStore $store): ResumeWorkflowHandler
    {
        $metadata = new InMemoryWorkflowMetadataStore();
        $registry = new WorkflowRegistry();
        $registry->registerClass(ImmediateWorkflow::class);
        $metadata->save(ExecutionId::fromString('exec-1'), ImmediateWorkflow::class, ['name' => 'Ada']);
        $engine = new ExecutionEngine($store, new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true));

        return new ResumeWorkflowHandler(
            $engine,
            $registry,
            $metadata,
            new NullWorkflowResumeDispatcher(),
            $store,
            new InMemoryChildWorkflowParentLinkStore(),
            new RecordingTimerDispatcher(),
            new WorkflowDefinitionLoader(),
        );
    }
}
