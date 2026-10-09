<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowTaskCompleted;
use Gplanchat\Durable\Event\WorkflowTaskScheduled;
use Gplanchat\Durable\Event\WorkflowTaskStarted;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\WorkflowTaskJournal;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use integration\Durable\Support\CallbackWorkflowResumeDispatcher;
use PHPUnit\Framework\TestCase;

final class TheWaitForAWorkerIsScheduledOnceTest extends TestCase
{
    public function testASecondDispatchBeforeAnyPickupAddsNothing(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($id, []));

        WorkflowTaskJournal::schedule($store, new CallbackWorkflowResumeDispatcher(static fn() => null), $id);
        WorkflowTaskJournal::schedule($store, new CallbackWorkflowResumeDispatcher(static fn() => null), $id);

        self::assertSame([ExecutionStarted::class, WorkflowTaskScheduled::class], $this->classes($store, $id));
    }

    public function testADispatchAfterThePassEndedSchedulesANewTask(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $store = new InMemoryEventStore();

        WorkflowTaskJournal::schedule($store, new CallbackWorkflowResumeDispatcher(static fn() => null), $id);
        $store->append(new WorkflowTaskStarted($id));
        WorkflowTaskJournal::schedule($store, new CallbackWorkflowResumeDispatcher(static fn() => null), $id);
        $store->append(new WorkflowTaskCompleted($id));
        WorkflowTaskJournal::schedule($store, new CallbackWorkflowResumeDispatcher(static fn() => null), $id);

        self::assertSame(
            [WorkflowTaskScheduled::class, WorkflowTaskStarted::class, WorkflowTaskScheduled::class, WorkflowTaskCompleted::class, WorkflowTaskScheduled::class],
            $this->classes($store, $id),
        );
    }

    public function testAnActivityOutcomeSchedulesTheTaskThatWillReadItBeforeTheResumeLeaves(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $store = new InMemoryEventStore();
        $executor = new RegistryActivityExecutor();
        $executor->register('charge', static fn(): string => 'ch_1');
        $atDispatch = [];
        $dispatcher = new CallbackWorkflowResumeDispatcher(function () use ($store, $id, &$atDispatch): void {
            $atDispatch = $this->classes($store, $id);
        });

        (new ActivityMessageProcessor($store, new InMemoryActivityTransport(), $executor, $dispatcher, $this->createMock(ActivityHeartbeatSenderInterface::class)))
            ->process(new ActivityMessage('exec-1', 'act-1', 'charge', []));

        self::assertSame([ActivityCompleted::class, WorkflowTaskScheduled::class], \array_slice($atDispatch, -2));
    }

    /**
     * @return list<class-string>
     */
    private function classes(InMemoryEventStore $store, ExecutionId $id): array
    {
        return array_map(static fn(object $event): string => $event::class, iterator_to_array($store->readStream($id), false));
    }
}
