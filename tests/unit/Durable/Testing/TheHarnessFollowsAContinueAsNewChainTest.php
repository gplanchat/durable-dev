<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Testing;

use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Exception\ContinuationCapReachedException;
use Gplanchat\Durable\Exception\WorkflowStuckException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\CounterWorkflow;
use unit\Durable\Fixtures\ForeverWorkflow;

/**
 * A run that calls `continueAsNew()` hands over to the next one, as `ResumeWorkflowHandler` does on
 * the journal backends, and the caller gets the last run's result (#802).
 */
final class TheHarnessFollowsAContinueAsNewChainTest extends TestCase
{
    public function testTheCallerGetsTheLastRunsResultAfterTwoContinuations(): void
    {
        $env = WorkflowTestEnvironment::inMemory();

        $result = $env->runWorkflowClass(CounterWorkflow::class, ['n' => 0], 'counter-0');

        self::assertSame('done at 2', $result);
    }

    public function testEachRunNamesTheOneItContinues(): void
    {
        $env = WorkflowTestEnvironment::inMemory();

        $env->runWorkflowClass(CounterWorkflow::class, ['n' => 0], 'counter-0');

        $chain = ['counter-0'];
        while (null !== $next = $this->successorOf($env, $chain[\count($chain) - 1])) {
            $started = $this->eventsOf($env, $next)[0] ?? null;
            self::assertInstanceOf(ExecutionStarted::class, $started);
            self::assertSame($chain[\count($chain) - 1], $started->payload()['continuedFromExecutionId'] ?? null);
            // The alias, as ResumeWorkflowHandler journals it on the journal backends.
            self::assertSame('counter', $started->payload()['workflowType'] ?? null);
            $chain[] = $next;
        }

        self::assertCount(3, $chain);
    }

    /**
     * An inline child is run by the same runner: its parent gets the chain's last result, as on
     * Temporal, where it used to fail with the child's ContinueAsNewRequested.
     */
    public function testAParentGetsTheLastRunsResultOfAChildThatContinuesAsNew(): void
    {
        $env = WorkflowTestEnvironment::inMemory();
        $env->registerWorkflowClass(CounterWorkflow::class);

        $result = $env->run(static fn(WorkflowEnvironment $wf): string
            => 'parent-saw:' . $wf->await($wf->childWorkflowStub(CounterWorkflow::class)->run(0)), 'parent-0');

        self::assertSame('parent-saw:done at 2', $result);
        self::assertCount(1, array_filter(
            $this->eventsOf($env, 'parent-0'),
            static fn(Event $event): bool => $event instanceof ChildWorkflowCompleted,
        ));
    }

    /**
     * A chain longer than the cap fails instead of running forever, and names where it started (#888).
     */
    public function testAWorkflowThatAlwaysContinuesAsNewFailsAtTheDefaultCap(): void
    {
        $env = WorkflowTestEnvironment::inMemory();

        try {
            $env->runWorkflowClass(ForeverWorkflow::class, ['n' => 0], 'forever-0');
            self::fail('The chain should have stopped at the cap.');
        } catch (WorkflowStuckException $e) {
            // Its own class, still caught where a stuck execution is caught.
            self::assertInstanceOf(ContinuationCapReachedException::class, $e);
            self::assertSame('forever-0', $e->executionId);
            self::assertStringContainsString('forever-0', $e->getMessage());
            self::assertStringContainsString('maxContinuations (10)', $e->getMessage());
        }
    }

    public function testAChainOfExactlyTheCapStillReturnsTheLastRunsResult(): void
    {
        $env = WorkflowTestEnvironment::inMemory(maxContinuations: 2);

        self::assertSame('done at 2', $env->runWorkflowClass(CounterWorkflow::class, ['n' => 0], 'counter-0'));
    }

    public function testTheCapIsConfigurable(): void
    {
        $env = WorkflowTestEnvironment::inMemory(maxContinuations: 1);

        $this->expectException(ContinuationCapReachedException::class);
        $this->expectExceptionMessage('Workflow counter-0 continued as new more often than maxContinuations (1) allows');

        $env->runWorkflowClass(CounterWorkflow::class, ['n' => 0], 'counter-0');
    }

    public function testACapOfZeroAllowsNoContinuation(): void
    {
        $env = WorkflowTestEnvironment::inMemory(maxContinuations: 0);

        $this->expectException(ContinuationCapReachedException::class);
        $this->expectExceptionMessage('maxContinuations (0)');

        $env->runWorkflowClass(CounterWorkflow::class, ['n' => 0], 'counter-0');
    }

    public function testANegativeCapFailsAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('-1');

        WorkflowTestEnvironment::inMemory(maxContinuations: -1);
    }

    private function successorOf(WorkflowTestEnvironment $env, string $executionId): ?string
    {
        foreach ($this->eventsOf($env, $executionId) as $event) {
            if ($event instanceof WorkflowContinuedAsNew) {
                return $event->newExecutionId()?->toString();
            }
        }

        return null;
    }

    /**
     * @return list<Event>
     */
    private function eventsOf(WorkflowTestEnvironment $env, string $executionId): array
    {
        return iterator_to_array($env->getEventStore()->readStream(ExecutionId::fromString($executionId)), false);
    }
}
