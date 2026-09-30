<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\FireWorkflowTimersHandler;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\PassEventStore;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\FrozenClock;

require_once __DIR__ . '/CoreResumeWithoutASymfonyBusTest.php';

/**
 * DUR052 §5: the timers due are named in a resume sent before they fire, and a plain resume
 * follows. No timer due, no resume: an early timer message would otherwise spin.
 */
final class DueTimersAreAnnouncedBeforeTheyFireTest extends TestCase
{
    public function testTheDueTimersAreNamedBeforeTheyFire(): void
    {
        $journal = new InMemoryEventStore();
        $journal->append(new TimerScheduled(ExecutionId::fromString('exec-1'), 'timer-1', 100.0, ''));
        $journal->append(new TimerScheduled(ExecutionId::fromString('exec-1'), 'timer-2', 900.0, ''));

        $resumes = $this->fire($journal, now: 500.0);

        self::assertSame(['awaiting timer timer-1 with 2 events', 'resume with 3 events'], $resumes->sent, 'timer-2 is not due yet');
    }

    public function testNoTimerDueSendsNoResume(): void
    {
        $journal = new InMemoryEventStore();
        $journal->append(new TimerScheduled(ExecutionId::fromString('exec-1'), 'timer-1', 900.0, ''));

        self::assertSame([], $this->fire($journal, now: 500.0)->sent);
    }

    public function testACancelledTimerIsNotAnnounced(): void
    {
        $journal = new InMemoryEventStore();
        $journal->append(new TimerScheduled(ExecutionId::fromString('exec-1'), 'timer-1', 100.0, ''));
        $journal->append(new TimerCancelled(ExecutionId::fromString('exec-1'), 'timer-1', 'superseded'));

        self::assertSame([], $this->fire($journal, now: 500.0)->sent);
    }

    /**
     * DUR053 with DUR052: firing timers is a fenced pass. A newer pass that claims the execution
     * after the announcement leaves the older one firing nothing; the newer pass owns the timers.
     */
    public function testATimerPassSupersededAfterItsAnnouncementFiresNothing(): void
    {
        $journal = new InMemoryEventStore();
        $journal->append(new TimerScheduled(ExecutionId::fromString('exec-1'), 'timer-1', 100.0, ''));
        $resumes = new TimerRecordingResumes($journal, takeOverOnAnnouncement: true);

        $this->fire($journal, now: 500.0, resumes: $resumes);

        self::assertSame(['awaiting timer timer-1 with 1 events'], $resumes->sent, 'announced, then neither fired nor resumed');
        self::assertSame(1, $journal->countEventsInStream(ExecutionId::fromString('exec-1')), 'no TimerCompleted from the superseded pass');
    }

    private function fire(InMemoryEventStore $journal, float $now, ?TimerRecordingResumes $resumes = null): TimerRecordingResumes
    {
        $resumes ??= new TimerRecordingResumes($journal);
        $runtime = new ExecutionRuntime($journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, new FrozenClock($now), true);

        (new FireWorkflowTimersHandler($journal, $runtime, $resumes, new RecordingTimerDispatcher()))(new FireWorkflowTimersMessage('exec-1'));

        return $resumes;
    }
}

final class TimerRecordingResumes implements WorkflowResumeDispatcher
{
    /** @var list<string> */
    public array $sent = [];

    public function __construct(
        private readonly InMemoryEventStore $journal,
        /** A second worker claims the execution as soon as the due timers are announced. */
        private readonly bool $takeOverOnAnnouncement = false,
    ) {}

    public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void
    {
        $this->sent[] = \sprintf('resume with %d events', $this->journal->countEventsInStream($executionId));
    }

    public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void
    {
        $this->sent[] = \sprintf('awaiting %s with %d events', $fact->describe(), $this->journal->countEventsInStream($executionId));
        if ($this->takeOverOnAnnouncement) {
            PassEventStore::open($this->journal, $executionId->toString());
        }
    }

    public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void {}
}
