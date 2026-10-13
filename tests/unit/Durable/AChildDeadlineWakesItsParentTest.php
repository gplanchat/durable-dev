<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Awaitable\Deferred;
use Gplanchat\Durable\Event\ChildWorkflowDeadline;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\FireWorkflowTimersHandler;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreWorkflowLifecycle;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Timer\TimerWakeDelayCalculator;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\FrozenClock;

require_once __DIR__ . '/DueTimersAreAnnouncedBeforeTheyFireTest.php';

/**
 * A parent that waits on a child with an execution timeout is woken at the deadline, and fails
 * the child's await with the text Temporal gives it (#979).
 */
final class AChildDeadlineWakesItsParentTest extends TestCase
{
    public function testTheParentWakesAtTheDeadline(): void
    {
        $journal = $this->journal(dueAt: 100.0);

        self::assertSame(5000, TimerWakeDelayCalculator::millisecondsUntilNextTimerDue($journal, ExecutionId::fromString('parent-1'), 95.0));
    }

    public function testASuspendedParentAsksForAWakeUp(): void
    {
        $journal = $this->journal(dueAt: 100.0);

        try {
            (new EventStoreWorkflowLifecycle($journal))->onSuspended(ExecutionId::fromString('parent-1'), (new Deferred())->awaitable());
            self::fail('a suspension is signalled by an exception');
        } catch (WorkflowSuspendedException $suspended) {
            self::assertTrue($suspended->waitingOnTimer());
        }
    }

    public function testTheHandlerFailsTheChildAndResumesTheParent(): void
    {
        $journal = $this->journal(dueAt: 100.0);

        $resumes = $this->fire($journal, now: 100.0);

        self::assertSame(['awaiting child child-1 with 3 events', 'resume with 3 events', 'resume with 5 events'], $resumes->sent, 'the child is resumed so that it replays to its end, then the parent');
        $parent = iterator_to_array($journal->readStream(ExecutionId::fromString('parent-1')), false);
        $failure = $parent[3];
        self::assertInstanceOf(ChildWorkflowFailed::class, $failure);
        self::assertSame('Child workflow child-1 timed out.', $failure->failureMessage());
        self::assertNull($failure->workflowFailureKind(), 'the same kind as the inline path, and as Temporal: none');
        $child = iterator_to_array($journal->readStream(ExecutionId::fromString('child-1')), false);
        self::assertContains(WorkflowExecutionFailed::class, array_map(static fn(object $e): string => $e::class, $child), 'the child no longer counts as running');
    }

    public function testBeforeTheDeadlineNothingIsFailedOrResumed(): void
    {
        $journal = $this->journal(dueAt: 100.0);

        self::assertSame([], $this->fire($journal, now: 99.0)->sent);
        self::assertSame(3, $journal->countEventsInStream(ExecutionId::fromString('parent-1')));
    }

    private function journal(float $dueAt): InMemoryEventStore
    {
        $journal = new InMemoryEventStore();
        $parent = ExecutionId::fromString('parent-1');
        $journal->append(new ExecutionStarted($parent, []));
        $journal->append(new ChildWorkflowDeadline($parent, ExecutionId::fromString('child-1'), $dueAt));
        $journal->append(new ChildWorkflowScheduled($parent, ExecutionId::fromString('child-1'), 'ChildType', []));
        $journal->append(new ExecutionStarted(ExecutionId::fromString('child-1'), []));

        return $journal;
    }

    private function fire(InMemoryEventStore $journal, float $now): TimerRecordingResumes
    {
        $resumes = new TimerRecordingResumes($journal);
        $runtime = new ExecutionRuntime($journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, new FrozenClock($now), true);

        (new FireWorkflowTimersHandler($journal, $runtime, $resumes, new RecordingTimerDispatcher()))(new FireWorkflowTimersMessage('parent-1'));

        return $resumes;
    }
}
