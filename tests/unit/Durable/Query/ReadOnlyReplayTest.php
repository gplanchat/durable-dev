<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Query;

use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Exception\WorkflowContinuedWithoutSuccessor;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Query\ReadOnlyReplay;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Testing\ConformanceClientWorkflow;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

final class ReadOnlyReplayTest extends TestCase
{
    private InMemoryEventStore $events;
    private InMemoryWorkflowMetadataStore $rows;
    private ReadOnlyReplay $replay;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->rows = new InMemoryWorkflowMetadataStore();
        $registry = new WorkflowRegistry();
        $registry->registerClass(ConformanceClientWorkflow::class);
        $engine = new ExecutionEngine(
            $this->events,
            new ExecutionRuntime($this->events, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true),
            workflowDefinitionLoader: new WorkflowDefinitionLoader(),
        );
        $this->replay = new ReadOnlyReplay($engine, $this->events, $this->rows, $registry);
    }

    public function testAQueryFollowsAContinueAsNewChainToItsLatestRun(): void
    {
        $first = ExecutionId::fromString('run-1');
        $next = ExecutionId::fromString('run-2');
        $this->rows->save($first, ConformanceClientWorkflow::TYPE, []);
        $this->rows->save($next, ConformanceClientWorkflow::TYPE, []);
        $this->events->append(new WorkflowSignalReceived($first, 'pay', []));
        $this->events->append(new WorkflowContinuedAsNew($first, ConformanceClientWorkflow::TYPE, [], [], $next));

        // The first run's journal alone would answer "paid": the answer comes from the latest run.
        self::assertSame('waiting', $this->replay->query($first, 'status'));
    }

    public function testASuccessorWithoutAStartRowFailsNamingTheIdTheCallerPassed(): void
    {
        $first = ExecutionId::fromString('run-1');
        $this->rows->save($first, ConformanceClientWorkflow::TYPE, []);
        $this->events->append(new WorkflowContinuedAsNew($first, ConformanceClientWorkflow::TYPE, [], [], ExecutionId::fromString('run-2')));

        try {
            $this->replay->query($first, 'status');
            self::fail('A successor without a row must fail.');
        } catch (WorkflowExecutionNotFound $e) {
            self::assertSame('run-1', $e->executionId);
        }
    }

    public function testARunThatContinuedAsNewWithoutNamingItsSuccessorFailsNamingTheRun(): void
    {
        $first = ExecutionId::fromString('run-1');
        $this->rows->save($first, ConformanceClientWorkflow::TYPE, []);
        $this->events->append(new WorkflowContinuedAsNew($first, ConformanceClientWorkflow::TYPE, []));

        try {
            $this->replay->query($first, 'status');
            self::fail('A chain without a successor must fail.');
        } catch (WorkflowContinuedWithoutSuccessor $e) {
            self::assertSame('run-1', $e->executionId);
        }
    }
}
