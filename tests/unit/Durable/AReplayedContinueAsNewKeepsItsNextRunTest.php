<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AnAsyncChildThatContinuesAsNewReportsToItsParentTest.php';

/**
 * A redelivered resume replays a run that already continued as new. The next id was decided on the
 * first pass and is in the journal: the replay reuses it and appends nothing (#878).
 */
final class AReplayedContinueAsNewKeepsItsNextRunTest extends TestCase
{
    public function testAReplayFindsOneContinuationAndOneNextRun(): void
    {
        $journal = new InMemoryEventStore();
        $metadata = new MarkCompletedFailsOnce(new InMemoryWorkflowMetadataStore());
        $registry = new WorkflowRegistry();
        $registry->registerClass(ContinuingChildWorkflow::class);
        $metadata->save(ExecutionId::fromString('run-1'), ContinuingChildWorkflow::class, ['n' => 0, 'failAtTheEnd' => false]);
        $resumes = new ContinuingChildResumes();
        $handler = new ResumeWorkflowHandler(
            new ExecutionEngine($journal, new ExecutionRuntime($journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true)),
            $registry,
            $metadata,
            $resumes,
            $journal,
            new InMemoryChildWorkflowParentLinkStore(),
            new RecordingTimerDispatcher(),
            new WorkflowDefinitionLoader(),
        );

        try {
            $handler(new ResumeWorkflowMessage('run-1'));
            self::fail('markCompleted() did not fail.');
        } catch (\LogicException) {
        }
        $handler(new ResumeWorkflowMessage('run-1'));

        $continuations = array_values(array_filter(
            iterator_to_array($journal->readStream(ExecutionId::fromString('run-1')), false),
            static fn(object $e): bool => $e instanceof WorkflowContinuedAsNew,
        ));
        self::assertCount(1, $continuations, 'the replay appends no second continuation');
        self::assertCount(1, array_unique($resumes->startedRuns), 'one next run, whatever the number of dispatches');
        self::assertSame($continuations[0]->newExecutionId()?->toString(), $resumes->startedRuns[0], 'the run that starts is the one the journal names');
    }
}
