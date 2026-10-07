<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Bundle\Messenger\MessengerWorkflowResumeDispatcher;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Laravel\Queue\InProcessWorkflowResumeDispatcher;
use Gplanchat\Durable\Laravel\Queue\LaravelWorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use unit\DurableLaravel\Fixtures\FakeQueue;
use unit\DurableLaravel\Fixtures\FakeQueueFactory;

/**
 * #918: run-a continued as run-b and is completed; a late resume of run-a sends run-b again. Run-b
 * completes in another worker right after the handler read it as active, and before the dispatcher
 * writes. The dispatcher must not reopen it.
 */
final class ARedispatchNeverReopensACompletedNextRunTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(WorkflowMetadataStore): WorkflowResumeDispatcher}>
     */
    public static function dispatchers(): iterable
    {
        yield 'Messenger' => [static fn(WorkflowMetadataStore $metadata): WorkflowResumeDispatcher => new MessengerWorkflowResumeDispatcher(
            new class implements MessageBusInterface {
                public function dispatch(object $message, array $stamps = []): Envelope
                {
                    return Envelope::wrap($message, $stamps);
                }
            },
            $metadata,
        )];
        yield 'Laravel queue' => [static fn(WorkflowMetadataStore $metadata): WorkflowResumeDispatcher => new LaravelWorkflowResumeDispatcher(
            new FakeQueueFactory(new FakeQueue()),
            $metadata,
        )];
        yield 'Laravel in-process' => [static fn(WorkflowMetadataStore $metadata): WorkflowResumeDispatcher => new InProcessWorkflowResumeDispatcher(
            $metadata,
            new InMemoryActivityTransport(),
            static fn(): \Closure => static function (): void {},
            static fn(): \Closure => static function (): void {},
            static fn(): \Closure => static function (): void {},
        )];
    }

    /**
     * @param \Closure(WorkflowMetadataStore): WorkflowResumeDispatcher $dispatcher
     */
    #[DataProvider('dispatchers')]
    public function testANextRunThatCompletesAfterTheGuardStaysCompleted(\Closure $dispatcher): void
    {
        $a = ExecutionId::fromString('run-a');
        $b = ExecutionId::fromString('run-b');
        $inner = new InMemoryWorkflowMetadataStore();
        $inner->save($a, 'test.chain', []);
        $inner->markCompleted($a);
        $inner->save($b, 'test.chain', []);
        $journal = new InMemoryEventStore();
        $journal->append(new ExecutionStarted($a, ['workflowType' => 'test.chain']));
        $journal->append(new WorkflowContinuedAsNew($a, 'test.chain', [], [], $b));
        $journal->append(new ExecutionStarted($b, ['workflowType' => 'test.chain', 'continuedFromExecutionId' => 'run-a']));

        $metadata = new CompletesAfterTheFirstRead($inner, $b);
        $handler = new ResumeWorkflowHandler(
            new ExecutionEngine($journal, new ExecutionRuntime($journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true)),
            new WorkflowRegistry(),
            $metadata,
            $dispatcher($metadata),
            $journal,
            new InMemoryChildWorkflowParentLinkStore(),
            new class implements WorkflowTimerDispatcher {
                public function dispatchTimerFire(ExecutionId $executionId, int $delayMs = 0): void {}
            },
            new WorkflowDefinitionLoader(),
        );

        $handler(new ResumeWorkflowMessage('run-a'));

        self::assertTrue($metadata->completedInBetween, 'the completion lands between the guard and the dispatch');
        self::assertFalse($inner->hasActiveWorkflowMetadata($b), 'run-b completed in between: the re-dispatch does not reopen it');
    }
}

/**
 * Another worker completes the run right after the first read of its row: the handler's guard.
 */
final class CompletesAfterTheFirstRead implements WorkflowMetadataStore
{
    public bool $completedInBetween = false;

    public function __construct(private readonly InMemoryWorkflowMetadataStore $inner, private readonly ExecutionId $run) {}

    public function get(ExecutionId $executionId): ?array
    {
        $row = $this->inner->get($executionId);
        if (!$this->completedInBetween && $executionId->toString() === $this->run->toString()) {
            $this->completedInBetween = true;
            $this->inner->markCompleted($executionId);
        }

        return $row;
    }

    public function save(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        $this->inner->save($executionId, $workflowType, $payload);
    }

    public function markCompleted(ExecutionId $executionId): void
    {
        $this->inner->markCompleted($executionId);
    }

    public function hasActiveWorkflowMetadata(ExecutionId $executionId): bool
    {
        return $this->inner->hasActiveWorkflowMetadata($executionId);
    }

    public function delete(ExecutionId $executionId): void
    {
        $this->inner->delete($executionId);
    }
}
