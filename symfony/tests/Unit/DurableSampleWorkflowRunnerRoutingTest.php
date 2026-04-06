<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Durable\DurableSampleWorkflowRunner;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Verifies the routing logic inside {@see DurableSampleWorkflowRunner::waitForWorkflowCompletion()}:
 *
 * - When WorkflowClient is absent (null): the in-memory Messenger drain is used,
 *   the result is read from the event store.
 * - The Temporal path (pollForCompletion) is exercised by temporal-integration tests;
 *   WorkflowClient is a final gRPC class and cannot be subclassed for unit tests.
 *   Extracting a WorkflowClientInterface would unlock a dedicated unit test here (future task).
 *
 * @internal
 */
#[CoversClass(DurableSampleWorkflowRunner::class)]
final class DurableSampleWorkflowRunnerRoutingTest extends TestCase
{
    /**
     * In-memory path: workflowClient = null → drain is used, result comes from the event store.
     */
    public function testInMemoryPathUsesEventStoreToDetermineCompletion(): void
    {
        $eventStore = new InMemoryEventStore();
        $metadataStore = new InMemoryWorkflowMetadataStore();
        $registry = new WorkflowRegistry();

        $resume = new class implements WorkflowResumeDispatcher {
            public function dispatchResume(string $executionId): void {}
            public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void {}
        };

        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return new Envelope($message, $stamps);
            }
        };

        $receiverLocator = new class implements ContainerInterface {
            public function get(string $id): mixed { return null; }
            public function has(string $id): bool { return false; }
        };

        $runner = new DurableSampleWorkflowRunner(
            $registry,
            $resume,
            $bus,
            $eventStore,
            $metadataStore,
            $receiverLocator,
            null,
        );

        // Pre-populate the event store so that DurableMessengerDrain detects completion
        // immediately (avoids a 120-second timeout in the drain loop).
        $executionId = 'routing-test-exec-001';
        $eventStore->append(new ExecutionStarted($executionId, []));
        $eventStore->append(new ExecutionCompleted($executionId, 'in-memory-result'));
        $metadataStore->markCompleted($executionId);

        $result = $runner->waitForWorkflowCompletion($executionId);

        self::assertSame(
            'in-memory-result',
            $result,
            'In-memory path must return the result stored in the event store.',
        );
    }

    /**
     * Verifies that dispatchWorkflowRun() calls the WorkflowResumeDispatcher and
     * returns an executionId (auto-generated when not supplied).
     */
    public function testDispatchWorkflowRunReturnsExecutionId(): void
    {
        $dispatched = [];

        $resume = new class ($dispatched) implements WorkflowResumeDispatcher {
            public function __construct(private array &$dispatched) {}
            public function dispatchResume(string $executionId): void {}
            public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void
            {
                $this->dispatched[] = ['executionId' => $executionId, 'type' => $workflowType];
            }
        };

        $runner = new DurableSampleWorkflowRunner(
            new WorkflowRegistry(),
            $resume,
            new class implements MessageBusInterface {
                public function dispatch(object $message, array $stamps = []): Envelope
                {
                    return new Envelope($message, $stamps);
                }
            },
            new InMemoryEventStore(),
            new InMemoryWorkflowMetadataStore(),
            new class implements ContainerInterface {
                public function get(string $id): mixed { return null; }
                public function has(string $id): bool { return false; }
            },
            null,
        );

        $executionId = $runner->dispatchWorkflowRun('SomeWorkflow', ['foo' => 'bar']);

        self::assertNotEmpty($executionId, 'dispatchWorkflowRun must return a non-empty executionId.');
        self::assertCount(1, $dispatched);
        self::assertSame($executionId, $dispatched[0]['executionId']);
        self::assertSame('SomeWorkflow', $dispatched[0]['type']);
    }

    /**
     * Verifies that an explicit executionId supplied to dispatchWorkflowRun is preserved.
     */
    public function testDispatchWorkflowRunPreservesExplicitExecutionId(): void
    {
        $dispatched = [];
        $resume = new class ($dispatched) implements WorkflowResumeDispatcher {
            public function __construct(private array &$dispatched) {}
            public function dispatchResume(string $executionId): void {}
            public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void
            {
                $this->dispatched[] = $executionId;
            }
        };

        $runner = new DurableSampleWorkflowRunner(
            new WorkflowRegistry(),
            $resume,
            new class implements MessageBusInterface {
                public function dispatch(object $message, array $stamps = []): Envelope
                {
                    return new Envelope($message, $stamps);
                }
            },
            new InMemoryEventStore(),
            new InMemoryWorkflowMetadataStore(),
            new class implements ContainerInterface {
                public function get(string $id): mixed { return null; }
                public function has(string $id): bool { return false; }
            },
            null,
        );

        $explicitId = 'my-custom-exec-id';
        $returned = $runner->dispatchWorkflowRun('SomeWorkflow', [], $explicitId);

        self::assertSame($explicitId, $returned);
        self::assertSame([$explicitId], $dispatched);
    }
}
