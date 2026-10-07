<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CoreResumeWithoutASymfonyBusTest.php';

/**
 * DUR052 §3: a child reports to its parent in the protocol's order. The resume naming the child,
 * the append unless the parent already holds it, the plain resume, and the parent link last: a
 * redelivered child resume then still finds the link, and resumes the parent.
 */
final class AChildReportsToItsParentInTheProtocolsOrderTest extends TestCase
{
    public function testTheParentIsAnnouncedFirstAndUnlinkedLast(): void
    {
        [$journal, $links, $resumes, $handler] = $this->childOf('parent-1');

        $handler(new ResumeWorkflowMessage('child-1'));

        self::assertSame([
            'awaiting child child-1 on parent-1 with 0 events, linked',
            'resume parent-1 with 1 events, linked',
        ], $resumes->sent);
        self::assertNull($links->getParentExecutionId(ExecutionId::fromString('child-1')), 'unlinked once the parent is resumed');
    }

    /**
     * The child died after the parent's append: its redelivered resume replays to completion, finds
     * the link and the outcome already there, and only resumes the parent.
     */
    public function testARedeliveryAfterTheAppendResumesTheParentWithoutASecondOutcome(): void
    {
        [$journal, , $resumes, $handler] = $this->childOf('parent-1');
        $journal->append(new ChildWorkflowCompleted(ExecutionId::fromString('parent-1'), ExecutionId::fromString('child-1'), 'Hello, Ada'));

        $handler(new ResumeWorkflowMessage('child-1'));

        $outcomes = array_filter(iterator_to_array($journal->readStream(ExecutionId::fromString('parent-1')), false), static fn(object $e): bool => $e instanceof ChildWorkflowCompleted);
        self::assertCount(1, $outcomes, 'the parent holds the outcome once');
        self::assertSame(['resume parent-1 with 1 events, linked'], $resumes->sent);
    }

    /**
     * @return array{InMemoryEventStore, InMemoryChildWorkflowParentLinkStore, ChildRecordingResumes, ResumeWorkflowHandler}
     */
    private function childOf(string $parentId): array
    {
        $journal = new InMemoryEventStore();
        $metadata = new InMemoryWorkflowMetadataStore();
        $links = new InMemoryChildWorkflowParentLinkStore();
        $registry = new WorkflowRegistry();
        $registry->registerClass(ImmediateWorkflow::class);
        $metadata->save(ExecutionId::fromString('child-1'), ImmediateWorkflow::class, ['name' => 'Ada']);
        $links->link(ExecutionId::fromString('child-1'), ExecutionId::fromString($parentId));
        $resumes = new ChildRecordingResumes($journal, $links);

        return [$journal, $links, $resumes, new ResumeWorkflowHandler(
            new ExecutionEngine($journal, new ExecutionRuntime($journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true)),
            $registry,
            $metadata,
            $resumes,
            $journal,
            $links,
            new RecordingTimerDispatcher(),
            new WorkflowDefinitionLoader(),
        )];
    }
}

final class ChildRecordingResumes implements WorkflowResumeDispatcher
{
    /** @var list<string> */
    public array $sent = [];

    public function __construct(
        private readonly InMemoryEventStore $journal,
        private readonly InMemoryChildWorkflowParentLinkStore $links,
    ) {}

    public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void
    {
        $this->sent[] = \sprintf('resume %s with %d events, %s', $executionId->toString(), $this->journal->countEventsInStream($executionId), $this->linked());
    }

    public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void
    {
        $this->sent[] = \sprintf('awaiting %s on %s with %d events, %s', $fact->describe(), $executionId->toString(), $this->journal->countEventsInStream($executionId), $this->linked());
    }

    public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void {}

    private function linked(): string
    {
        return null !== $this->links->getParentExecutionId(ExecutionId::fromString('child-1')) ? 'linked' : 'unlinked';
    }
}
