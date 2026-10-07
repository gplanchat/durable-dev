<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\ChildWorkflowRunner;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Exception\ChildWorkflowStartDeferred;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
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
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ARedispatchNeverReopensACompletedNextRunTest.php';

#[AsWorkflow(name: 'test.failing-child')]
final class FailingChildWorkflow
{
    #[AsWorkflowMethod]
    public function run(): string
    {
        throw new \RuntimeException('the child fails');
    }
}

/**
 * The default reuse policy, `AllowDuplicateFailedOnly`, lets a parent start a child under the id of
 * a child that failed. The failed child's row is completed; the new start must run it again and
 * report to the new parent (#918 review).
 */
final class AReusedAsyncChildIdRunsAgainTest extends TestCase
{
    /**
     * @param \Closure(WorkflowMetadataStore): WorkflowResumeDispatcher $dispatcher
     */
    #[DataProviderExternal(ARedispatchNeverReopensACompletedNextRunTest::class, 'dispatchers')]
    public function testTheNewParentHearsFromTheReusedChild(\Closure $dispatcher): void
    {
        $journal = new InMemoryEventStore();
        $metadata = new InMemoryWorkflowMetadataStore();
        $links = new InMemoryChildWorkflowParentLinkStore();
        $registry = new WorkflowRegistry();
        $registry->registerClass(FailingChildWorkflow::class);
        $runtime = new ExecutionRuntime($journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true);
        $dispatch = $dispatcher($metadata);
        $children = new ChildWorkflowRunner($journal, $runtime, $registry, new RegistryActivityExecutor(), 0, true, $dispatch, $links, metadataStore: $metadata);
        $handler = new ResumeWorkflowHandler(
            new ExecutionEngine($journal, $runtime),
            $registry,
            $metadata,
            $dispatch,
            $journal,
            $links,
            new class implements WorkflowTimerDispatcher {
                public function dispatchTimerFire(ExecutionId $executionId, int $delayMs = 0): void {}
            },
            new WorkflowDefinitionLoader(),
        );
        $child = ExecutionId::fromString('child-x');

        foreach (['parent-1', 'parent-2'] as $parent) {
            try {
                $children->runChild($child, 'test.failing-child', [], ExecutionId::fromString($parent));
                self::fail('an async child start is deferred');
            } catch (ChildWorkflowStartDeferred) {
            }

            try {
                $handler(new ResumeWorkflowMessage('child-x'));
            } catch (\RuntimeException) {
            }
        }

        $outcomes = array_filter(
            iterator_to_array($journal->readStream(ExecutionId::fromString('parent-2')), false),
            static fn(object $e): bool => $e instanceof ChildWorkflowCompleted || $e instanceof ChildWorkflowFailed,
        );
        self::assertCount(1, $outcomes, 'the second parent hears from the child it started');
    }
}
