<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\Handler;

use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use PHPUnit\Framework\TestCase;

/**
 * DUR052 §4: a delivered signal is announced before it is journalled, journalled with its request id,
 * and a redelivered message journals nothing more but still resumes.
 */
final class ASignalIsDeliveredOnceAndAnnouncedFirstTest extends TestCase
{
    public function testTheResumeNamingTheSignalLeavesBeforeTheAppend(): void
    {
        $journal = new InMemoryEventStore();
        $resumes = new RecordingResumes($journal);

        (new DeliverWorkflowSignalHandler($journal, $resumes))(new DeliverWorkflowSignalMessage('exec-1', 'approve', [], 'req-1'));

        self::assertSame(['awaiting signal req-1 with 0 events', 'resume with 2 events'], $resumes->sent);
    }

    public function testARedeliveredSignalIsJournalledOnceAndStillResumes(): void
    {
        $journal = new InMemoryEventStore();
        $resumes = new RecordingResumes($journal);
        $handler = new DeliverWorkflowSignalHandler($journal, $resumes);
        $message = new DeliverWorkflowSignalMessage('exec-1', 'approve', [], 'req-1');

        $handler($message);
        $handler($message);

        $signals = array_filter(iterator_to_array($journal->readStream(ExecutionId::fromString('exec-1')), false), static fn(object $e): bool => $e instanceof WorkflowSignalReceived);
        self::assertCount(1, $signals, 'the workflow sees the signal once');
        self::assertSame('resume with 2 events', $resumes->sent[array_key_last($resumes->sent)], 'the redelivery still resumes');
    }
}

final class RecordingResumes implements WorkflowResumeDispatcher
{
    /** @var list<string> */
    public array $sent = [];

    public function __construct(private readonly InMemoryEventStore $journal) {}

    public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void
    {
        $this->sent[] = \sprintf('resume with %d events', $this->journal->countEventsInStream($executionId));
    }

    public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void
    {
        $this->sent[] = \sprintf('awaiting %s with %d events', $fact->describe(), $this->journal->countEventsInStream($executionId));
    }

    public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void {}
}
