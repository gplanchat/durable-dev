<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Testing;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Testing\ActivitySpy;
use Gplanchat\Durable\Testing\DurableTestCase;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\Attributes\Test;
use unit\Durable\Fixtures\SuiteActivities;

/**
 * Examples of how to use the in-memory testing infrastructure.
 *
 * This file serves both as functional validation and as documentation
 * by example for the users of the component.
 */
final class WorkflowTestingExampleTest extends DurableTestCase
{
    // -------------------------------------------------------------------------
    // 1. Pure unit test: DurableTestCase + ActivitySpy
    // -------------------------------------------------------------------------

    #[Test]
    public function workflowWithSingleActivityCompletes(): void
    {
        $spy = ActivitySpy::returns('Hello, World!');

        $env = $this->createWorkflowTestEnvironment(['greet' => $spy]);

        $result = $env->run(
            static function (WorkflowEnvironment $wf): string {
                return (string) $wf->await($wf->activityStub(SuiteActivities::class)->greet('World'));
            },
            $executionId = 'exec-example-001',
        );

        self::assertSame('Hello, World!', $result);

        $spy->assertCalledTimes(1);
        $spy->assertCalledWith(['name' => 'World']);

        $this->assertWorkflowCompleted($executionId, 'Hello, World!');
        $this->assertActivityExecuted($executionId, 'greet');
        $this->assertEventStoreContains($executionId, \Gplanchat\Durable\Event\ExecutionCompleted::class);
    }

    #[Test]
    public function workflowWithParallelActivitiesCompletes(): void
    {
        $doublespy = ActivitySpy::returns(6);
        $squareSpy = ActivitySpy::returns(16);

        $env = $this->createWorkflowTestEnvironment([
            'double' => $doublespy,
            'square' => $squareSpy,
        ]);

        $result = $env->run(
            static function (WorkflowEnvironment $wf): array {
                return $wf->await($wf->all(
                    $wf->activityStub(SuiteActivities::class)->double(3),
                    $wf->activityStub(SuiteActivities::class)->square(4),
                ));
            },
            $executionId = 'exec-parallel-001',
        );

        self::assertIsArray($result);
        self::assertSame(6, $result[0]);
        self::assertSame(16, $result[1]);

        $doublespy->assertCalledTimes(1);
        $squareSpy->assertCalledTimes(1);
        $this->assertWorkflowCompleted($executionId, [6, 16]);
    }

    #[Test]
    public function workflowWithRetryHandlesActivityFailure(): void
    {
        // The Throwables in the sequence are thrown on the corresponding call
        $spy = ActivitySpy::returnsSequence(
            new \RuntimeException('Temporary failure'),
            new \RuntimeException('Still failing'),
            'Success after retries',
        );

        $env = WorkflowTestEnvironment::inMemory(['flaky' => $spy], maxActivityRetries: 2);

        $result = $env->run(
            static function (WorkflowEnvironment $wf): string {
                try {
                    return (string) $wf->await($wf->activityStub(SuiteActivities::class)->flaky());
                } catch (\Throwable $e) {
                    return 'caught: ' . $e->getMessage();
                }
            },
            $executionId = 'exec-retry-001',
        );

        self::assertStringContainsString('Success after retries', (string) $result);
        $spy->assertCalledTimes(3);
    }

    #[Test]
    public function workflowFailsWhenActivityThrowsUnhandled(): void
    {
        $spy = ActivitySpy::throws(new \DomainException('Business rule violated', 42));

        $env = $this->createWorkflowTestEnvironment(['validate' => $spy]);

        $executionId = 'exec-fail-001';

        $this->expectException(\Throwable::class);

        $env->run(
            static function (WorkflowEnvironment $wf): void {
                // RetryLimit::once() — with no bound, attempts are unlimited (Temporal
                // semantics) and the activity would be retried instead of failing the workflow.
                $wf->await($wf->activityStub(SuiteActivities::class, new ActivityOptions(RetryLimit::once()))->validate('invalid'));
            },
            $executionId,
        );
    }

    #[Test]
    public function workflowFailureIsRecordedInEventStore(): void
    {
        $spy = ActivitySpy::throws(new \RuntimeException('Activity bombed'));

        $env = $this->createWorkflowTestEnvironment(['explode' => $spy]);

        $executionId = 'exec-fail-002';

        try {
            $env->run(
                static function (WorkflowEnvironment $wf): void {
                    $wf->await($wf->activityStub(SuiteActivities::class, new ActivityOptions(RetryLimit::once()))->explodeNow());
                },
                $executionId,
            );
        } catch (\Throwable) {
            // The exception propagates; we check the event store contains WorkflowExecutionFailed
        }

        $this->assertWorkflowFailed($executionId);
    }

    // -------------------------------------------------------------------------
    // 2. ActivitySpy: sequence of return values
    // -------------------------------------------------------------------------

    #[Test]
    public function spyReturnsSequentialValues(): void
    {
        $spy = ActivitySpy::returnsSequence('first', 'second', 'third');

        $env = WorkflowTestEnvironment::inMemory(['step' => $spy]);

        $result = $env->run(
            static function (WorkflowEnvironment $wf): array {
                return [
                    $wf->await($wf->activityStub(SuiteActivities::class)->step()),
                    $wf->await($wf->activityStub(SuiteActivities::class)->step()),
                    $wf->await($wf->activityStub(SuiteActivities::class)->step()),
                ];
            },
        );

        self::assertSame(['first', 'second', 'third'], $result);
        $spy->assertCalledTimes(3);
    }

    #[Test]
    public function spyLastValueIsRepeatedWhenSequenceExhausted(): void
    {
        $spy = ActivitySpy::returnsSequence('value-1', 'value-2');

        $env = WorkflowTestEnvironment::inMemory(['step' => $spy]);

        $result = $env->run(
            static function (WorkflowEnvironment $wf): array {
                return [
                    $wf->await($wf->activityStub(SuiteActivities::class)->step()),
                    $wf->await($wf->activityStub(SuiteActivities::class)->step()),
                    $wf->await($wf->activityStub(SuiteActivities::class)->step()), // sequence exhausted: repeats the last one
                ];
            },
        );

        self::assertSame(['value-1', 'value-2', 'value-2'], $result);
    }

    // -------------------------------------------------------------------------
    // 3. Standalone WorkflowTestEnvironment (without DurableTestCase)
    // -------------------------------------------------------------------------

    #[Test]
    public function standaloneEnvironmentCanBeUsedWithoutTestCase(): void
    {
        $env = WorkflowTestEnvironment::inMemory([
            'compute' => static fn(array $p): int => ($p['a'] ?? 0) + ($p['b'] ?? 0),
        ]);

        $executionId = 'exec-standalone-001';

        $result = $env->run(
            static function (WorkflowEnvironment $wf): int {
                return (int) $wf->await($wf->activityStub(SuiteActivities::class)->compute(3, 4));
            },
            $executionId,
        );

        self::assertSame(7, $result);

        // Direct inspection of the journal
        $hasCompleted = false;
        foreach ($env->getEventStore()->readStream($executionId) as $event) {
            if ($event instanceof \Gplanchat\Durable\Event\ExecutionCompleted) {
                $hasCompleted = true;
                break;
            }
        }
        self::assertTrue($hasCompleted, 'The journal must contain ExecutionCompleted');
    }

    // -------------------------------------------------------------------------
    // 4. countActivityExecutions helper
    // -------------------------------------------------------------------------

    #[Test]
    public function countActivityExecutionsCountsCorrectly(): void
    {
        $env = $this->createWorkflowTestEnvironment([
            'add' => static fn(array $p): int => ($p['a'] ?? 0) + ($p['b'] ?? 0),
        ]);

        $env->run(
            static function (WorkflowEnvironment $wf): array {
                return $wf->await($wf->all(
                    $wf->activityStub(SuiteActivities::class)->add(1, 2),
                    $wf->activityStub(SuiteActivities::class)->add(3, 4),
                    $wf->activityStub(SuiteActivities::class)->add(5, 6),
                ));
            },
            $executionId = 'exec-count-001',
        );

        self::assertSame(3, $this->countActivityExecutions($executionId, 'add'));
    }
}
