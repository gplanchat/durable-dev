<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Worker;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ActivityMessageProcessor::class)]
final class ActivityMessageProcessorTest extends TestCase
{
    #[Test]
    public function processAppendsCompletedAndDispatchesResume(): void
    {
        $events = [];
        $eventStore = new class($events) implements EventStoreInterface {
            /**
             * @param array<int, Event> $events
             */
            public function __construct(private array &$events)
            {
            }

            public function append(Event $event): void
            {
                $this->events[] = $event;
            }

            public function readStream(string $executionId): iterable
            {
                foreach ($this->events as $event) {
                    if ($event->executionId() === $executionId) {
                        yield $event;
                    }
                }
            }

            public function countEventsInStream(string $executionId): int
            {
                $n = 0;
                foreach ($this->events as $event) {
                    if ($event->executionId() === $executionId) {
                        ++$n;
                    }
                }

                return $n;
            }
        };

        $transport = new InMemoryActivityTransport();
        $executor = new RegistryActivityExecutor();
        $executor->register('demo', static fn () => 'ok');

        $resumed = [];
        $resume = new class($resumed) implements WorkflowResumeDispatcher {
            /**
             * @param array<int, string> $resumed
             */
            public function __construct(private array &$resumed)
            {
            }

            public function dispatchResume(string $executionId): void
            {
                $this->resumed = array_merge($this->resumed, [$executionId]);
            }

            public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void
            {
            }
        };

        $processor = new ActivityMessageProcessor($eventStore, $transport, $executor, $resume, 0);
        $processor->process(new ActivityMessage('exec-1', 'act-1', 'demo', ['x' => 1]));

        self::assertCount(1, $events);
        self::assertInstanceOf(ActivityCompleted::class, $events[0]);
        self::assertSame(['exec-1'], $resumed);
    }
}
