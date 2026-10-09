<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CoreResumeWithoutASymfonyBusTest.php';

#[AsWorkflow(name: 'test.continuing-child')]
final class ContinuingChildWorkflow
{
    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env, int $n = 0, bool $failAtTheEnd = false): string
    {
        if ($n < 2) {
            $env->continueAsNew(self::class, ['n' => $n + 1, 'failAtTheEnd' => $failAtTheEnd]);
        }
        if ($failAtTheEnd) {
            throw new \RuntimeException("failed at {$n}");
        }

        return "done at {$n}";
    }
}

/**
 * The parent awaits the child under the id it started it with. On Temporal it hears from the last
 * run of the child's continue-as-new chain; on the journal backends the parent link follows the
 * chain and the last run reports under that first id (#859).
 */
final class AnAsyncChildThatContinuesAsNewReportsToItsParentTest extends TestCase
{
    public function testTheParentGetsTheLastRunsResultAfterTwoContinuations(): void
    {
        [$journal, $links, $resumes, $handler] = $this->childOf('parent-1', failAtTheEnd: false);

        $last = $this->continueTwice($journal, $links, $handler);
        $handler(new ResumeWorkflowMessage($last));

        $outcomes = $this->parentOutcomes($journal, 'parent-1');
        self::assertCount(1, $outcomes);
        self::assertInstanceOf(ChildWorkflowCompleted::class, $outcomes[0]);
        self::assertSame('child-1', $outcomes[0]->childExecutionId()->toString(), 'the id the parent scheduled');
        self::assertSame('done at 2', $outcomes[0]->result());
        self::assertContains('resume parent-1', $resumes->sent);
        self::assertNull($links->getParentExecutionId(ExecutionId::fromString($last)), 'unlinked once the parent is resumed');
    }

    public function testTheParentGetsTheLastRunsFailure(): void
    {
        [$journal, $links, , $handler] = $this->childOf('parent-1', failAtTheEnd: true);
        $last = $this->continueTwice($journal, $links, $handler);

        try {
            $handler(new ResumeWorkflowMessage($last));
            self::fail('The last run did not fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('failed at 2', $e->getMessage());
        }

        $outcomes = $this->parentOutcomes($journal, 'parent-1');
        self::assertCount(1, $outcomes);
        self::assertInstanceOf(ChildWorkflowFailed::class, $outcomes[0]);
        self::assertSame('child-1', $outcomes[0]->childExecutionId()->toString());
        self::assertSame('failed at 2', $outcomes[0]->failureMessage());
    }

    /**
     * The old run could not be marked completed: the redelivery replays it, and the run that
     * actually starts is still linked to the parent.
     */
    public function testARetriedContinuationKeepsTheStartedRunLinked(): void
    {
        $metadata = new MarkCompletedFailsOnce(new InMemoryWorkflowMetadataStore());
        [$journal, $links, $resumes, $handler] = $this->childOf('parent-1', failAtTheEnd: false, metadata: $metadata);

        try {
            $handler(new ResumeWorkflowMessage('child-1'));
            self::fail('markCompleted() did not fail.');
        } catch (\LogicException) {
        }
        $handler(new ResumeWorkflowMessage('child-1'));

        $started = end($resumes->startedRuns);
        self::assertIsString($started, 'the redelivery starts a run');
        self::assertSame('parent-1', $links->getParentExecutionId(ExecutionId::fromString($started))?->toString(), 'the run that starts is linked');

        $handler(new ResumeWorkflowMessage($started));
        $handler(new ResumeWorkflowMessage($this->successorOf($journal, $started)));

        $outcomes = $this->parentOutcomes($journal, 'parent-1');
        self::assertCount(1, $outcomes);
        self::assertInstanceOf(ChildWorkflowCompleted::class, $outcomes[0]);
        self::assertSame('child-1', $outcomes[0]->childExecutionId()->toString());
        self::assertSame('done at 2', $outcomes[0]->result());
    }

    /**
     * Resumes child-1, then the run it continues as, and checks the link moves with every step.
     *
     * @return string the id of the last run, not resumed yet
     */
    private function continueTwice(InMemoryEventStore $journal, InMemoryChildWorkflowParentLinkStore $links, ResumeWorkflowHandler $handler): string
    {
        $current = 'child-1';
        for ($step = 0; $step < 2; ++$step) {
            $handler(new ResumeWorkflowMessage($current));
            $next = $this->successorOf($journal, $current);
            self::assertNull($links->getParentExecutionId(ExecutionId::fromString($current)), 'the link left the superseded run');
            self::assertSame('parent-1', $links->getParentExecutionId(ExecutionId::fromString($next))?->toString(), 'the link follows the chain');
            $current = $next;
        }

        return $current;
    }

    private function successorOf(InMemoryEventStore $journal, string $executionId): string
    {
        foreach ($journal->readStream(ExecutionId::fromString($executionId)) as $event) {
            if ($event instanceof WorkflowContinuedAsNew && null !== $event->newExecutionId()) {
                return $event->newExecutionId()->toString();
            }
        }
        self::fail("{$executionId} did not continue as new.");
    }

    /**
     * @return list<ChildWorkflowCompleted|ChildWorkflowFailed>
     */
    private function parentOutcomes(InMemoryEventStore $journal, string $parentId): array
    {
        return array_values(array_filter(
            iterator_to_array($journal->readStream(ExecutionId::fromString($parentId)), false),
            static fn(object $e): bool => $e instanceof ChildWorkflowCompleted || $e instanceof ChildWorkflowFailed,
        ));
    }

    /**
     * @return array{InMemoryEventStore, InMemoryChildWorkflowParentLinkStore, ContinuingChildResumes, ResumeWorkflowHandler}
     */
    private function childOf(string $parentId, bool $failAtTheEnd, ?WorkflowMetadataStore $metadata = null): array
    {
        $journal = new InMemoryEventStore();
        $metadata ??= new InMemoryWorkflowMetadataStore();
        $links = new InMemoryChildWorkflowParentLinkStore();
        $registry = new WorkflowRegistry();
        $registry->registerClass(ContinuingChildWorkflow::class);
        $metadata->save(ExecutionId::fromString('child-1'), ContinuingChildWorkflow::class, ['n' => 0, 'failAtTheEnd' => $failAtTheEnd]);
        $links->link(ExecutionId::fromString('child-1'), ExecutionId::fromString($parentId));
        $resumes = new ContinuingChildResumes();

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

final class ContinuingChildResumes implements WorkflowResumeDispatcher
{
    /** @var list<string> */
    public array $sent = [];

    /** @var list<string> */
    public array $startedRuns = [];

    public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void
    {
        $this->sent[] = 'resume ' . $executionId->toString();
    }

    public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void
    {
        $this->sent[] = 'awaiting ' . $fact->describe() . ' on ' . $executionId->toString();
    }

    public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        $this->startedRuns[] = $executionId->toString();
    }
}

final class MarkCompletedFailsOnce implements WorkflowMetadataStore
{
    private bool $failed = false;

    public function __construct(private readonly WorkflowMetadataStore $inner) {}

    public function save(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        $this->inner->save($executionId, $workflowType, $payload);
    }

    public function insertIfAbsent(ExecutionId $executionId, string $workflowType, array $payload): bool
    {
        return $this->inner->insertIfAbsent($executionId, $workflowType, $payload);
    }

    public function markCompleted(ExecutionId $executionId): void
    {
        if (!$this->failed) {
            $this->failed = true;

            throw new \LogicException('The database went away.');
        }
        $this->inner->markCompleted($executionId);
    }

    public function get(ExecutionId $executionId): ?array
    {
        return $this->inner->get($executionId);
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
