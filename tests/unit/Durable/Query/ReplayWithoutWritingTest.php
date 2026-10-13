<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Query;

use Gplanchat\Durable\Attribute\AsQueryMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Exception\ReadOnlyPassNeedsDistributedRuntime;
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
