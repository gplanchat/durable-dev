<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Query;

use Gplanchat\Durable\Attribute\AsQueryMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Exception\ReadOnlyPassNeedsDistributedRuntime;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Testing\ConformanceActivities;
use Gplanchat\Durable\Testing\ConformanceChildWorkflow;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AsWorkflow('test.query.activity')]
final class WaitsOnAnActivity
{
    private string $state = 'started';

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): void
    {
        try {
            $this->state = 'waiting';
            $env->await($env->activityStub(ConformanceActivities::class)->quote(['a']));
            $this->state = 'done';
        } catch (\Throwable) {
            $this->state = 'compensated';
        }
    }

    #[AsQueryMethod('state')]
    public function state(): string
    {
        return $this->state;
    }
}

#[AsWorkflow('test.query.child')]
final class WaitsOnAChild
{
    private string $state = 'started';

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): void
    {
        try {
            $this->state = 'waiting';
            $env->await($env->childWorkflowStub(ConformanceChildWorkflow::class)->run('x'));
            $this->state = 'done';
        } catch (\Throwable) {
            $this->state = 'compensated';
        }
    }

    #[AsQueryMethod('state')]
    public function state(): string
    {
        return $this->state;
    }
}

#[AsWorkflow('test.query.side_effect')]
final class RunsASideEffect
{
    public static int $runs = 0;

    private string $state = 'started';

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): void
    {
        $env->sideEffect(static fn(): int => ++self::$runs);
        $this->state = 'after';
    }

    #[AsQueryMethod('state')]
    public function state(): string
    {
        return $this->state;
    }
}

#[AsWorkflow('test.query.activity_then_fail')]
final class AwaitsAnActivityThenFails
{
    private string $state = 'started';

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): void
    {
        $this->state = 'waiting';
        $env->await($env->activityStub(ConformanceActivities::class)->quote(['a']));

        throw new \RuntimeException('the workflow fails on its own');
    }

    #[AsQueryMethod('state')]
    public function state(): string
    {
        return $this->state;
    }
}

final class ReplayWithoutWritingTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function workflows(): iterable
    {
        yield 'an activity' => ['test.query.activity'];
        yield 'a child' => ['test.query.child'];
    }

    #[DataProvider('workflows')]
    public function testACommandTheJournalDoesNotHoldYetSuspendsTheWorkflowInsteadOfFailingIt(string $type): void
    {
        $events = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $engine = $this->engine($events, $transport, true);
        $id = ExecutionId::fromString('exec-1');

        $context = $engine->replayWithoutWriting($id, $this->registry()->getHandler($type, []));

        self::assertSame('waiting', $context->queryHandlers()->call('state'));
        self::assertSame(0, $events->countEventsInStream($id), 'The pass wrote to the journal.');
        self::assertNull($transport->nextDueAt(), 'The pass queued an activity.');
    }

    public function testASideEffectTheJournalDoesNotHoldIsNotRunByTheQueryAndRunsOnceInTheLivePass(): void
    {
        RunsASideEffect::$runs = 0;
        $events = new InMemoryEventStore();
        $engine = $this->engine($events, new InMemoryActivityTransport(), true);
        $id = ExecutionId::fromString('exec-1');
        $handler = $this->registry()->getHandler('test.query.side_effect', []);

        $context = $engine->replayWithoutWriting($id, $handler);

        self::assertSame('started', $context->queryHandlers()->call('state'));
        self::assertSame(0, RunsASideEffect::$runs, 'The query ran the closure.');

        $engine->start($id, $this->registry()->getHandler('test.query.side_effect', []));

        self::assertSame(1, RunsASideEffect::$runs);
    }

    public function testACancellationTheJournalHoldsIsDeliveredLikeAJournalledSignal(): void
    {
        $events = new InMemoryEventStore();
        $id = ExecutionId::fromString('exec-1');
        $events->append(new WorkflowCancellationRequested($id, 'requested'));

        $context = $this->engine($events, new InMemoryActivityTransport(), true)
            ->replayWithoutWriting($id, $this->registry()->getHandler('test.query.activity', []));

        self::assertSame('compensated', $context->queryHandlers()->call('state'));
        self::assertSame(1, $events->countEventsInStream($id));
    }

    public function testAReplayThatDivergesFromTheJournalFailsTheQuery(): void
    {
        $events = new InMemoryEventStore();
        $id = ExecutionId::fromString('exec-1');
        $events->append(new ActivityScheduled($id, 'activity-1', 'another.activity', []));

        $this->expectException(WorkflowTaskFailure::class);
        $this->engine($events, new InMemoryActivityTransport(), true)
            ->replayWithoutWriting($id, $this->registry()->getHandler('test.query.activity_then_fail', []));
    }

    public function testAWorkflowThatFailsOnItsOwnStillAnswersTheQuery(): void
    {
        $events = new InMemoryEventStore();
        $id = ExecutionId::fromString('exec-1');
        $events->append(new ActivityScheduled($id, 'activity-1', 'durable.conformance.quote', ['lines' => ['a']]));
        $events->append(new \Gplanchat\Durable\Event\ActivityCompleted($id, 'activity-1', 'ok'));

        $context = $this->engine($events, new InMemoryActivityTransport(), true)
            ->replayWithoutWriting($id, $this->registry()->getHandler('test.query.activity_then_fail', []));

        self::assertSame('waiting', $context->queryHandlers()->call('state'));
    }

    public function testARuntimeThatRunsWorkItselfIsRefused(): void
    {
        $engine = $this->engine(new InMemoryEventStore(), new InMemoryActivityTransport(), false);

        $this->expectException(ReadOnlyPassNeedsDistributedRuntime::class);
        $engine->replayWithoutWriting(ExecutionId::fromString('exec-1'), $this->registry()->getHandler('test.query.activity', []));
    }

    private function registry(): WorkflowRegistry
    {
        $registry = new WorkflowRegistry();
        $registry->registerClass(WaitsOnAnActivity::class);
        $registry->registerClass(WaitsOnAChild::class);
        $registry->registerClass(RunsASideEffect::class);
        $registry->registerClass(AwaitsAnActivityThenFails::class);
        $registry->registerClass(ConformanceChildWorkflow::class);

        return $registry;
    }

    private function engine(InMemoryEventStore $events, InMemoryActivityTransport $transport, bool $distributed): ExecutionEngine
    {
        return new ExecutionEngine(
            $events,
            new ExecutionRuntime($events, $transport, new RegistryActivityExecutor(), 0, null, $distributed),
            workflowDefinitionLoader: new WorkflowDefinitionLoader(),
        );
    }
}
