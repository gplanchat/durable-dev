<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Worker;

use Gplanchat\Durable\ActivityExecutor;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use PHPUnit\Framework\TestCase;

final class ActivityMessageProcessorCancellationTest extends TestCase
{
    public function testCancellationCheckerSkipsExecuteAndAppendsActivityCancelled(): void
    {
        $eventStore = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $executor = new class implements ActivityExecutor {
            public function register(string $activityName, callable $handler): void
            {
            }

            public function execute(string $activityName, array $payload): mixed
            {
                throw new \RuntimeException('execute must not run when cancellation is requested');
            }
        };
        $resumed = [];
        $resume = new class ($resumed) implements WorkflowResumeDispatcher {
            public function __construct(private array &$resumed)
            {
            }

            public function dispatchResume(string $executionId): void
            {
                $this->resumed[] = $executionId;
            }

            public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void
            {
            }
        };

        $heartbeatSender = new class implements ActivityHeartbeatSenderInterface {
            public function sendHeartbeat(mixed $details = null): bool
            {
                return true;
            }

            public function isCancellationRequested(): bool
            {
                return true;
            }
        };

        $processor = new ActivityMessageProcessor(
            $eventStore,
            $transport,
            $executor,
            $resume,
            $heartbeatSender,
            0,
            null,
        );

        $processor->process(new ActivityMessage(
            'exec-1',
            'act-1',
            'Echo',
            [],
            ['attempt' => 1],
        ));

        $cancelled = false;
        foreach ($eventStore->readStream('exec-1') as $e) {
            if ($e instanceof ActivityCancelled && $e->activityId() === 'act-1') {
                $cancelled = true;
            }
            if ($e instanceof ActivityCompleted && $e->activityId() === 'act-1') {
                self::fail('ActivityCompleted must not be appended when cancelled');
            }
        }
        self::assertTrue($cancelled);
        self::assertSame(['exec-1'], $resumed);
    }

    public function testCancellationAfterExecuteSkipsActivityCompleted(): void
    {
        $eventStore = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $executor = new class implements ActivityExecutor {
            public function register(string $activityName, callable $handler): void
            {
            }

            public function execute(string $activityName, array $payload): mixed
            {
                return 'done';
            }
        };
        $resumed = [];
        $resume = new class ($resumed) implements WorkflowResumeDispatcher {
            public function __construct(private array &$resumed)
            {
            }

            public function dispatchResume(string $executionId): void
            {
                $this->resumed[] = $executionId;
            }

            public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void
            {
            }
        };

        $heartbeatSender = new class implements ActivityHeartbeatSenderInterface {
            private int $polls = 0;

            public function sendHeartbeat(mixed $details = null): bool
            {
                return $this->isCancellationRequested();
            }

            public function isCancellationRequested(): bool
            {
                ++$this->polls;

                return $this->polls >= 2;
            }
        };

        $processor = new ActivityMessageProcessor(
            $eventStore,
            $transport,
            $executor,
            $resume,
            $heartbeatSender,
            0,
            null,
        );

        $processor->process(new ActivityMessage(
            'exec-2',
            'act-2',
            'Echo',
            [],
            ['attempt' => 1],
        ));

        $completed = false;
        $cancelled = false;
        foreach ($eventStore->readStream('exec-2') as $e) {
            if ($e instanceof ActivityCompleted && $e->activityId() === 'act-2') {
                $completed = true;
            }
            if ($e instanceof ActivityCancelled && $e->activityId() === 'act-2') {
                $cancelled = true;
            }
        }
        self::assertTrue($cancelled);
        self::assertFalse($completed);
        self::assertSame(['exec-2'], $resumed);
    }
}
