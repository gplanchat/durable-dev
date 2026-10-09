<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Testing;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\ContinuationCapReachedException;
use Gplanchat\Durable\Exception\WorkflowStuckException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Testing\DurableTestCase;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use unit\Durable\Fixtures\CounterWorkflow;
use unit\Durable\Fixtures\SuiteActivities;

/**
 * A test case sets the runner's budget and continuation cap through createWorkflowTestEnvironment()
 * and createWorkflowRunner(), as it sets maxActivityRetries (#897).
 */
final class DurableTestCaseForwardsTheRunnerSettingsTest extends DurableTestCase
{
    public function testTheEnvironmentUsesTheBudgetItIsGiven(): void
    {
        $env = $this->createWorkflowTestEnvironment(self::alwaysFailing(), budgetSeconds: 0.5);

        $this->expectException(WorkflowStuckException::class);
        $this->expectExceptionMessage('did not finish within 0.5s');

        self::runAlwaysFailing($env);
    }

    public function testTheRunnerUsesTheBudgetItIsGiven(): void
    {
        $runner = $this->createWorkflowRunner(self::alwaysFailing(), budgetSeconds: 0.5);

        $this->expectException(WorkflowStuckException::class);
        $this->expectExceptionMessage('did not finish within 0.5s');

        $runner->run(ExecutionId::fromString('runaway-1'), self::alwaysFailingWorkflow());
    }

    public function testTheEnvironmentUsesTheContinuationCapItIsGiven(): void
    {
        $env = $this->createWorkflowTestEnvironment(maxContinuations: 1);

        $this->expectException(ContinuationCapReachedException::class);
        $this->expectExceptionMessage('maxContinuations (1)');

        $env->runWorkflowClass(CounterWorkflow::class, ['n' => 0], 'counter-0');
    }

    public function testTheRunnerUsesTheContinuationCapItIsGiven(): void
    {
        $runner = $this->createWorkflowRunner(maxContinuations: 1);

        $this->expectException(ContinuationCapReachedException::class);
        $this->expectExceptionMessage('maxContinuations (1)');

        $env = $this->requireCurrentEnvironment();
        $env->registerWorkflowClass(CounterWorkflow::class);

        $runner->run(ExecutionId::fromString('counter-0'), $env->getWorkflowRegistry()->getHandler(CounterWorkflow::class, ['n' => 0]));
    }

    /**
     * @return array<string, callable(array<string, mixed>): mixed>
     */
    private static function alwaysFailing(): array
    {
        return ['always' => static function (): never {
            throw new \RuntimeException('boom');
        }];
    }

    /**
     * @return callable(WorkflowEnvironment): mixed
     */
    private static function alwaysFailingWorkflow(): callable
    {
        return static fn(WorkflowEnvironment $wf): mixed => $wf->await(
            $wf->activityStub(SuiteActivities::class, new ActivityOptions(initialInterval: Duration::seconds(0.05)))->always(),
        );
    }

    private static function runAlwaysFailing(WorkflowTestEnvironment $env): void
    {
        $env->run(self::alwaysFailingWorkflow(), 'runaway-1');
    }
}
