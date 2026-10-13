<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\ChildWorkflowDeadline;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\FireWorkflowTimersHandler;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\FrozenClock;

require_once __DIR__ . '/DueTimersAreAnnouncedBeforeTheyFireTest.php';

/**
 * An async child whose parent gave up on it ends in its own journal and does not run again: its
 * own timer, or a start still queued, would otherwise complete it after the failure (#979).
 */
final class ATimedOutAsyncChildStopsTest extends TestCase
{
    public function testAChildWhoseStartIsStillQueuedIsEndedToo(): void
    {
        $journal = $this->parentWaitingOnChild();

        $this->fire($journal);

        $child = iterator_to_array($journal->readStream(ExecutionId::fromString('child-1')), false);
        self::assertCount(1, $child);
        self::assertInstanceOf(WorkflowExecutionFailed::class, $child[0]);
    }

    public function testAChildThatReportedFirstIsNotEndedAgain(): void
    {
        $journal = $this->parentWaitingOnChild();
        $journal->append(new ExecutionStarted(ExecutionId::fromString('child-1'), []));
        $journal->append(new ExecutionCompleted(ExecutionId::fromString('child-1'), 'done'));

        $this->fire($journal);

        self::assertSame(2, $journal->countEventsInStream(ExecutionId::fromString('child-1')));
    }

    public function testAResumeOfAnEndedChildRunsNothingAndClosesItsRow(): void
    {
        $journal = $this->parentWaitingOnChild();
        $this->fire($journal);
        $metadata = new InMemoryWorkflowMetadataStore();
        $links = new InMemoryChildWorkflowParentLinkStore();
        $registry = new WorkflowRegistry();
        $registry->registerClass(ImmediateWorkflow::class);
        $metadata->save(ExecutionId::fromString('child-1'), ImmediateWorkflow::class, ['name' => 'Ada']);
        $links->link(ExecutionId::fromString('child-1'), ExecutionId::fromString('parent-1'));

        (new ResumeWorkflowHandler(
            new ExecutionEngine($journal, new ExecutionRuntime($journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true)),
            $registry,
            $metadata,
            new TimerRecordingResumes($journal),
            $journal,
            $links,
            new RecordingTimerDispatcher(),
            new WorkflowDefinitionLoader(),
        ))(new ResumeWorkflowMessage('child-1'));

        $child = iterator_to_array($journal->readStream(ExecutionId::fromString('child-1')), false);
        self::assertCount(1, $child, 'no ExecutionCompleted after the failure');
        self::assertTrue($metadata->get(ExecutionId::fromString('child-1'))['completed'] ?? false);
        self::assertNull($links->getParentExecutionId(ExecutionId::fromString('child-1')));
    }

    private function parentWaitingOnChild(): InMemoryEventStore
    {
        $journal = new InMemoryEventStore();
        $parent = ExecutionId::fromString('parent-1');
        $journal->append(new ChildWorkflowDeadline($parent, ExecutionId::fromString('child-1'), 100.0));
        $journal->append(new ChildWorkflowScheduled($parent, ExecutionId::fromString('child-1'), 'ChildType', []));

        return $journal;
    }

    private function fire(InMemoryEventStore $journal): void
    {
        $runtime = new ExecutionRuntime($journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, new FrozenClock(200.0), true);
        (new FireWorkflowTimersHandler($journal, $runtime, new TimerRecordingResumes($journal), new RecordingTimerDispatcher()))(new FireWorkflowTimersMessage('parent-1'));
    }
}
