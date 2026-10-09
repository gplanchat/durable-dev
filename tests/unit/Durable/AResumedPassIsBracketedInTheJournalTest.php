<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowTaskCompleted;
use Gplanchat\Durable\Event\WorkflowTaskStarted;
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

#[AsWorkflow(name: 'test.snooze')]
final class SnoozeWorkflow
{
    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): string
    {
        $env->sleep(3600);

        return 'awake';
    }
}

#[AsWorkflow(name: 'test.explode')]
final class ExplodeWorkflow
{
    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): string
    {
        throw new \RuntimeException('boom');
    }
}

/**
 * A worker takes a resume: the journal says when (WorkflowTaskStarted) and when the pass ended
 * (WorkflowTaskCompleted), whichever way it ended (#850 item 3).
 */
final class AResumedPassIsBracketedInTheJournalTest extends TestCase
{
    public function testASuspendedPassIsStartedThenCompletedAroundItsOwnEvents(): void
    {
        $journal = $this->resumeOnce(SnoozeWorkflow::class);

        self::assertSame(
            [WorkflowTaskStarted::class, TimerScheduled::class, WorkflowTaskCompleted::class],
            array_map(static fn($event): string => $event::class, $journal),
        );
    }

    public function testAFailedPassIsCompletedBeforeTheFailureLeavesTheHandler(): void
    {
        try {
            $this->resumeOnce(ExplodeWorkflow::class);
            self::fail('the failure is rethrown');
        } catch (\RuntimeException) {
        }

        $classes = array_map(static fn($event): string => $event::class, iterator_to_array($this->store->readStream(ExecutionId::fromString('exec-1')), false));
        self::assertSame(WorkflowTaskStarted::class, $classes[0]);
        self::assertSame(WorkflowTaskCompleted::class, $classes[array_key_last($classes)]);
    }

    private InMemoryEventStore $store;

    /**
     * @return list<\Gplanchat\Durable\Event\Event>
     */
    private function resumeOnce(string $class): array
    {
        $this->store = $store = new InMemoryEventStore();
        $metadata = new InMemoryWorkflowMetadataStore();
        $registry = new WorkflowRegistry();
        $registry->registerClass($class);
        $metadata->save(ExecutionId::fromString('exec-1'), $class, []);
        $handler = new ResumeWorkflowHandler(
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
        );
        $handler(new ResumeWorkflowMessage('exec-1'));

        return iterator_to_array($store->readStream(ExecutionId::fromString('exec-1')), false);
    }
}
