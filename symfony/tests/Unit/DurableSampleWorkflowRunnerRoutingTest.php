<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Transport\AwaitedFact;
use App\Durable\DurableSampleWorkflowRunner;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
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
 * - When WorkflowClientInterface is absent (null): the in-memory Messenger drain is used,
 *   the result is read from the event store.
 * - When WorkflowClientInterface is present: pollForCompletion() is called (Temporal path).
 *
 * @internal
 */
#[CoversClass(DurableSampleWorkflowRunner::class)]
final class DurableSampleWorkflowRunnerRoutingTest extends TestCase
{
    /**
     * Temporal path: workflowClient is present → pollForCompletion() is called and its return
     * value is forwarded directly to the caller.
     */
    public function testTemporalPathDelegatesToPollForCompletion(): void
    {
        $polledExecutionId = null;
        $fakeClient = new class ($polledExecutionId) implements WorkflowClientInterface {
            public function __construct(private ?string &$polledExecutionId) {}

            public function startAsync(string $workflowType, array $payload, ExecutionId $executionId, ?\Gplanchat\Durable\WorkflowStartOptions $options = null): ExecutionId
            {
                return $executionId;
            }

            public function startSync(string $workflowType, array $payload, ExecutionId $executionId, ?\Gplanchat\Durable\WorkflowStartOptions $options = null): mixed
            {
                return null;
            }

            public function pollForCompletion(string $executionId, int $refreshIntervalMs = 500, int $maxRefreshes = 120): mixed
            {
                $this->polledExecutionId = $executionId;

                return 'temporal-result';
            }

            public function signal(string $workflowId, \BackedEnum|string $signalName, array $args = [], ?string $requestId = null): void {}

            public function query(string $workflowId, string $queryType, array $args = []): mixed
            {
                return null;
            }

            public function update(string $workflowId, string $updateName, array $args = [], ?string $updateId = null): mixed
            {
                return null;
            }

            public function workflowId(ExecutionId $executionId): string
            {
                return 'durable-'.$executionId->toString();
            }
        };

        $resume = new class implements WorkflowResumeDispatcher {
            public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void {}
            public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void {}

            public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void {}
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
            $fakeClient,
        );

        $result = $runner->waitForWorkflowCompletion('temporal-exec-001');

        self::assertSame('temporal-result', $result, 'Temporal path must return pollForCompletion() value.');
        self::assertSame('temporal-exec-001', $polledExecutionId, 'pollForCompletion() must receive the execution ID.');
    }

    /**
     * In-memory path: workflowClient = null → drain is used, result comes from the event store.
     */
    public function testInMemoryPathUsesEventStoreToDetermineCompletion(): void
    {
        $eventStore = new InMemoryEventStore();
        $metadataStore = new InMemoryWorkflowMetadataStore();
        $registry = new WorkflowRegistry();

        $resume = new class implements WorkflowResumeDispatcher {
            public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void {}
            public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void {}

            public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void {}
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
        $eventStore->append(new ExecutionStarted(ExecutionId::fromString($executionId), []));
        $eventStore->append(new ExecutionCompleted(ExecutionId::fromString($executionId), 'in-memory-result'));
        $metadataStore->markCompleted(ExecutionId::fromString($executionId));

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
            public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void {}
            public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void {}

            public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
            {
                $this->dispatched[] = ['executionId' => $executionId->toString(), 'type' => $workflowType];
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
            public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void {}
            public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void {}

            public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
            {
                $this->dispatched[] = $executionId->toString();
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
