<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Worker;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ActivityMessageProcessor::class)]
final class ActivityMessageProcessorTest extends TestCase
{
    /**
     * @test
     */
    public function processAppendsCompletedAndDispatchesResume(): void
    {
        $events = [];
        $eventStore = new class($events) implements EventStoreInterface {
            public function __construct(private array &$events)
            {
            }

            public function append(\Gplanchat\Durable\Event\Event $event): void
            {
                $this->events[] = $event;
            }

            public function readStream(string $executionId): iterable
            {
                return [];
            }
        };

        $transport = new InMemoryActivityTransport();
        $executor = new RegistryActivityExecutor();
        $executor->register('demo', static fn () => 'ok');

        $resumed = [];
        $resume = new class($resumed) implements WorkflowResumeDispatcher {
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

        $processor = new ActivityMessageProcessor($eventStore, $transport, $executor, $resume, 0);
        $processor->process(new ActivityMessage('exec-1', 'act-1', 'demo', ['x' => 1]));

        self::assertCount(1, $events);
        self::assertInstanceOf(ActivityCompleted::class, $events[0]);
        self::assertSame(['exec-1'], $resumed);
    }
}
