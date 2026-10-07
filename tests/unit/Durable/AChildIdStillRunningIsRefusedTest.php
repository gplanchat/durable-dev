<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\ChildWorkflowRunner;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Exception\ChildWorkflowIdInUseException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowIdReusePolicy;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ARedispatchNeverReopensACompletedNextRunTest.php';
require_once __DIR__ . '/AReusedAsyncChildIdRunsAgainTest.php';

/**
 * A child id whose run has not finished is refused under every reuse policy, whichever parent
 * holds it: reusing it kept the earlier run's type and input and moved its parent link (#950).
 */
final class AChildIdStillRunningIsRefusedTest extends TestCase
{
    /**
     * @return iterable<string, array{WorkflowIdReusePolicy}>
     */
    public static function policies(): iterable
    {
        foreach (WorkflowIdReusePolicy::cases() as $policy) {
            yield $policy->name => [$policy];
        }
    }

    #[DataProvider('policies')]
    public function testAJournalWithoutAnOutcomeIsRunning(WorkflowIdReusePolicy $policy): void
    {
        $journal = new InMemoryEventStore();
        $journal->append(new ExecutionStarted(ExecutionId::fromString('child-x'), ['workflowType' => 'test.child']));
        $runtime = new ExecutionRuntime($journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true);
        $engine = new ExecutionEngine($journal, $runtime, new ChildWorkflowRunner($journal, $runtime, new WorkflowRegistry(), new RegistryActivityExecutor()));

        $this->expectException(ChildWorkflowIdInUseException::class);
        $engine->start(ExecutionId::fromString('parent-2'), static fn(WorkflowEnvironment $env): mixed
            => $env->await($env->childWorkflowStub(FailingChildWorkflow::class, new ChildWorkflowOptions(workflowId: 'child-x', workflowIdReusePolicy: $policy))->run()));
    }

    public function testAFinishedJournalIsNotRunning(): void
    {
        $journal = new InMemoryEventStore();
        $child = ExecutionId::fromString('child-x');
        $journal->append(new ExecutionStarted($child, ['workflowType' => 'test.child']));
        $journal->append(new ExecutionCompleted($child, 'done'));
        $runtime = new ExecutionRuntime($journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true);
        $engine = new ExecutionEngine($journal, $runtime, new ChildWorkflowRunner($journal, $runtime, new WorkflowRegistry(), new RegistryActivityExecutor()));

        // AllowDuplicate may reuse a finished id; the refusal is the running one only.
        try {
            $engine->start(ExecutionId::fromString('parent-2'), static fn(WorkflowEnvironment $env): mixed
                => $env->await($env->childWorkflowStub(FailingChildWorkflow::class, new ChildWorkflowOptions(workflowId: 'child-x', workflowIdReusePolicy: WorkflowIdReusePolicy::AllowDuplicate))->run()));
        } catch (ChildWorkflowIdInUseException $e) {
            self::fail('a finished id is not in use: ' . $e->getMessage());
        } catch (\Throwable) {
            // the child type is not registered: it fails after the policy let it through
        }
        $this->addToAssertionCount(1);
    }

    /**
     * @param \Closure(WorkflowMetadataStore): WorkflowResumeDispatcher $dispatcher
     */
    #[DataProviderExternal(ARedispatchNeverReopensACompletedNextRunTest::class, 'dispatchers')]
    public function testARunningMetadataRowRefusesEveryPolicy(\Closure $dispatcher): void
    {
        foreach (WorkflowIdReusePolicy::cases() as $policy) {
            $journal = new InMemoryEventStore();
            $metadata = new InMemoryWorkflowMetadataStore();
            $metadata->save(ExecutionId::fromString('child-x'), 'test.earlier', ['n' => 1]);
            $runtime = new ExecutionRuntime($journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true);
            $children = new ChildWorkflowRunner(
                $journal,
                $runtime,
                new WorkflowRegistry(),
                new RegistryActivityExecutor(),
                0,
                true,
                $dispatcher($metadata),
                new InMemoryChildWorkflowParentLinkStore(),
                metadataStore: $metadata,
            );
            $engine = new ExecutionEngine($journal, $runtime, $children);

            try {
                $engine->start(ExecutionId::fromString('parent-2'), static fn(WorkflowEnvironment $env): mixed
                    => $env->await($env->childWorkflowStub(FailingChildWorkflow::class, new ChildWorkflowOptions(workflowId: 'child-x', workflowIdReusePolicy: $policy))->run()));
                self::fail($policy->name . ': a running id is refused');
            } catch (ChildWorkflowIdInUseException) {
                self::assertSame('test.earlier', $metadata->get(ExecutionId::fromString('child-x'))['workflowType'] ?? null);
            }
        }
    }

    public function testAnAsyncRunnerRefusesAMissingMetadataStore(): void
    {
        $journal = new InMemoryEventStore();
        $runtime = new ExecutionRuntime($journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true);
        $dispatch = $this->createStub(WorkflowResumeDispatcher::class);

        $this->expectException(\InvalidArgumentException::class);
        new ChildWorkflowRunner($journal, $runtime, new WorkflowRegistry(), new RegistryActivityExecutor(), 0, true, $dispatch, new InMemoryChildWorkflowParentLinkStore());
    }
}
