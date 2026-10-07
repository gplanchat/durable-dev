<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
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
use PHPUnit\Framework\TestCase;

/**
 * #946: two passes of run-a both read run-b as having no row. The other pass has already written
 * it and run-b has completed. The slower pass's write must not reopen run-b.
 */
final class AContinueAsNewPassCannotResetACompletedNextRunTest extends TestCase
{
    public function testTheSlowerPassLeavesTheCompletedNextRunCompleted(): void
    {
        $a = ExecutionId::fromString('run-a');
        $b = ExecutionId::fromString('run-b');
        $inner = new InMemoryWorkflowMetadataStore();
        $inner->save($a, 'test.chain', []);
        $inner->markCompleted($a);
        $inner->save($b, 'test.chain', []);
        $inner->markCompleted($b);
        $journal = new InMemoryEventStore();
        $journal->append(new ExecutionStarted($a, ['workflowType' => 'test.chain']));
        $journal->append(new WorkflowContinuedAsNew($a, 'test.chain', [], [], $b));
        $journal->append(new ExecutionStarted($b, ['workflowType' => 'test.chain', 'continuedFromExecutionId' => 'run-a']));

        $handler = new ResumeWorkflowHandler(
            new ExecutionEngine($journal, new ExecutionRuntime($journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true)),
            new WorkflowRegistry(),
            new ReadsTheNextRunAsMissing($inner, $b),
            new NullWorkflowResumeDispatcher(),
            $journal,
            new InMemoryChildWorkflowParentLinkStore(),
            new class implements WorkflowTimerDispatcher {
                public function dispatchTimerFire(ExecutionId $executionId, int $delayMs = 0): void {}
            },
            new WorkflowDefinitionLoader(),
        );

        $handler(new ResumeWorkflowMessage('run-a'));

        self::assertFalse($inner->hasActiveWorkflowMetadata($b), 'the stale read must not turn into a reset of run-b');
    }
}

/**
 * The pass read before the other one wrote: every read of the run returns no row.
 */
final class ReadsTheNextRunAsMissing implements WorkflowMetadataStore
{
    public function __construct(private readonly InMemoryWorkflowMetadataStore $inner, private readonly ExecutionId $run) {}

    public function get(ExecutionId $executionId): ?array
    {
        return $executionId->toString() === $this->run->toString() ? null : $this->inner->get($executionId);
    }

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
