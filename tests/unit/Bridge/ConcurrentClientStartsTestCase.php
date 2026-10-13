<?php

declare(strict_types=1);

namespace unit\Bridge;

use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowClient;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\Store\ReopensCompletedWorkflowMetadata;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\AwaitedFact;
use PHPUnit\Framework\TestCase;

/**
 * Two starts of one id on a SQL backend (task 2.7 of `workflow-repository`): one wins, and the
 * first execution keeps its input. Each client has its own store object over the one database; the
 * second start runs inside the first one's window, between its check and its write, which is the
 * interleaving two processes can produce. A race between real processes did not run.
 */
abstract class ConcurrentClientStartsTestCase extends TestCase
{
    /**
     * Two store objects over one database.
     *
     * @return array{WorkflowMetadataStore&ReopensCompletedWorkflowMetadata, WorkflowMetadataStore&ReopensCompletedWorkflowMetadata}
     */
    abstract protected function twoStoresOverOneDatabase(): array;

    private int $dispatched = 0;

    public function testOfTwoStartsOfOneIdTheSecondInsertFailsAndTheFirstInputStays(): void
    {
        [$first, $second] = $this->twoStoresOverOneDatabase();
        $id = ExecutionId::fromString('exec-1');
        $winner = $this->client($second);
        $loser = $this->client($this->before($first, static fn() => $winner->start($id, 'App\\Winner', ['v' => 'winner'])));

        try {
            $loser->start($id, 'App\\Loser', ['v' => 'loser']);
            self::fail('The start that writes second must fail.');
        } catch (WorkflowExecutionAlreadyStarted $e) {
            self::assertSame('exec-1', $e->executionId);
        }

        self::assertSame(['workflowType' => 'App\\Winner', 'payload' => ['v' => 'winner'], 'completed' => false], $first->get($id));
        self::assertSame(1, $this->dispatched, 'the loser dispatched no run');
    }

    public function testOfTwoRestartsAfterAFailedExecutionTheConditionalUpdateLetsOneWin(): void
    {
        [$first, $second] = $this->twoStoresOverOneDatabase();
        $id = ExecutionId::fromString('exec-1');
        $this->client($first)->start($id, 'App\\Old', ['v' => 'old']);
        $this->dispatched = 0;
        $first->markCompleted($id);
        $winner = $this->client($second, true);
        $loser = $this->client($this->before($first, static fn() => $winner->start($id, 'App\\Winner', ['v' => 'winner'])), true);

        try {
            $loser->start($id, 'App\\Loser', ['v' => 'loser']);
            self::fail('The restart that updates second must fail.');
        } catch (WorkflowExecutionAlreadyStarted $e) {
            self::assertSame('exec-1', $e->executionId);
        }

        self::assertSame(['workflowType' => 'App\\Winner', 'payload' => ['v' => 'winner'], 'completed' => false], $first->get($id));
        self::assertSame(1, $this->dispatched, 'the loser dispatched no run');
    }

    private function client(WorkflowMetadataStore&ReopensCompletedWorkflowMetadata $store, bool $failed = false): InMemoryWorkflowClient
    {
        $catalog = new InMemoryWorkflowRunCatalog(new InMemoryEventStore());
        $projecting = new ProjectingWorkflowMetadataStore($store, $catalog);
        $dispatcher = new class ($this->dispatched) implements WorkflowResumeDispatcher {
            public function __construct(private int &$count) {}

            public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void {}

            public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void {}

            public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
            {
                ++$this->count;
            }
        };

        // A restart: both clients judge the execution failed, so the store is the arbiter.
        if ($failed) {
            $catalog->recordStart(ExecutionId::fromString('exec-1'), 'App\\Old');
            $catalog->recordOutcome(ExecutionId::fromString('exec-1'), WorkflowRunStatus::Failed);
        }

        return new InMemoryWorkflowClient($projecting, $dispatcher, $catalog);
    }

    /**
     * The store, with `$competitor` run once just before its first write.
     *
     * @return WorkflowMetadataStore&ReopensCompletedWorkflowMetadata
     */
    private function before(WorkflowMetadataStore&ReopensCompletedWorkflowMetadata $inner, \Closure $competitor): WorkflowMetadataStore&ReopensCompletedWorkflowMetadata
    {
        return new class ($inner, $competitor) implements WorkflowMetadataStore, ReopensCompletedWorkflowMetadata {
            public function __construct(
                private readonly WorkflowMetadataStore&ReopensCompletedWorkflowMetadata $inner,
                private ?\Closure $competitor,
            ) {}

            private function compete(): void
            {
                $competitor = $this->competitor;
                $this->competitor = null;
                $competitor?->__invoke();
            }

            public function save(ExecutionId $executionId, string $workflowType, array $payload): void
            {
                $this->inner->save($executionId, $workflowType, $payload);
            }

            public function insertIfAbsent(ExecutionId $executionId, string $workflowType, array $payload): bool
            {
                $this->compete();

                return $this->inner->insertIfAbsent($executionId, $workflowType, $payload);
            }

            public function reopenIfCompleted(ExecutionId $executionId, string $workflowType, array $payload): bool
            {
                $this->compete();

                return $this->inner->reopenIfCompleted($executionId, $workflowType, $payload);
            }

            public function markCompleted(ExecutionId $executionId): void
            {
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
        };
    }
}
