<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsUpdateMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
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
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The resume turns the entries of `pendingUpdates` into pending updates: the update id they carry
 * reaches the journalled event, and an entry queued without one journals none.
 */
final class AResumeJournalsTheUpdateIdOfItsPendingUpdatesTest extends TestCase
{
    public function testTheUpdateIdOfAnEntryIsJournalled(): void
    {
        $handled = $this->resume(['name' => 'amend', 'arguments' => [], 'updateId' => 'upd-1']);

        self::assertSame('upd-1', $handled->updateId());
    }

    public function testAnEntryQueuedBeforeTheFieldExistedJournalsNone(): void
    {
        $handled = $this->resume(['name' => 'amend', 'arguments' => []]);

        self::assertNull($handled->updateId());
    }

    /**
     * @param array{name: string, arguments: array<string, mixed>, updateId?: string|null} $update
     */
    private function resume(array $update): WorkflowUpdateHandled
    {
        $store = new InMemoryEventStore();
        $metadata = new InMemoryWorkflowMetadataStore();
        $metadata->save(ExecutionId::fromString('exec-1'), UpdatableWorkflow::class, []);
        $registry = new WorkflowRegistry();
        $registry->registerClass(UpdatableWorkflow::class);

        (new ResumeWorkflowHandler(
            new ExecutionEngine($store, new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true)),
            $registry,
            $metadata,
            new NullWorkflowResumeDispatcher(),
            $store,
            new InMemoryChildWorkflowParentLinkStore(),
            new class implements WorkflowTimerDispatcher {
                public function dispatchTimerFire(ExecutionId $executionId, int $delayMs = 0): void {}
            },
            new WorkflowDefinitionLoader(),
        ))(new ResumeWorkflowMessage('exec-1', [$update]));

        $handled = array_values(array_filter(iterator_to_array($store->readStream(ExecutionId::fromString('exec-1')), false), static fn(object $e): bool => $e instanceof WorkflowUpdateHandled));
        self::assertCount(1, $handled);

        return $handled[0];
    }
}

#[AsWorkflow('test.updatable')]
final class UpdatableWorkflow
{
    private bool $amended = false;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsUpdateMethod('amend')]
    public function amend(): string
    {
        $this->amended = true;

        return 'ok';
    }

    #[AsWorkflowMethod]
    public function run(): string
    {
        $this->environment->await(fn(): bool => $this->amended);

        return 'done';
    }
}
