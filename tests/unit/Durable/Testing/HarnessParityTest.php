<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Testing;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\WorkflowStuckException;
use Gplanchat\Durable\Failure\ActivityRetryState;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\SuiteActivities;

/**
 * Le harness public exécutait les activités par un chemin distinct de la production
 * (ni marqueurs worker, ni timeouts, ni politique de retry par activité) et construisait son
 * moteur sans runner d'enfant ni coordinateur parent/enfant. Un workflow s'appuyant sur l'un
 * de ces comportements passait en test et divergeait en production.
 */
final class HarnessParityTest extends TestCase
{
    public function testActivityJournalHasTheSameShapeAsProduction(): void
    {
        $env = WorkflowTestEnvironment::inMemory(['greet' => static fn(array $p): string => 'hi ' . $p['name']]);

        $result = $env->run(static fn(WorkflowEnvironment $wf): mixed
            => $wf->await($wf->activityStub(SuiteActivities::class)->greet('world')), 'exec-1');

        self::assertSame('hi world', $result);
        self::assertSame(
            [
                'ExecutionStarted',
                'ActivityScheduled',
                'ActivityTaskStarted',
                'ActivityTaskCompleted',
                'ActivityCompleted',
                'ExecutionCompleted',
            ],
            $this->shortNames($env, 'exec-1'),
        );
    }

    public function testPerActivityRetryPolicyIsHonoredByTheHarness(): void
    {
        $runs = 0;
        $env = WorkflowTestEnvironment::inMemory([
            'flaky' => static function () use (&$runs): never {
                ++$runs;

                throw new \DomainException('refused');
            },
        ]);

        $options = new ActivityOptions(RetryLimit::ofAttempts(5), nonRetryableExceptions: [\DomainException::class]);

        try {
            $env->run(static fn(WorkflowEnvironment $wf): mixed
                => $wf->await($wf->activityStub(SuiteActivities::class, $options)->flaky()), 'exec-2');
        } catch (\Throwable) {
            // Le workflow ne gère pas l'échec : attendu ici.
        }

        self::assertSame(1, $runs, 'une exception non-retryable ne doit pas être retentée');
        $failed = $this->firstOf($env, 'exec-2', ActivityFailed::class);
        self::assertNotNull($failed);
        self::assertSame(ActivityRetryState::NonRetryableFailure, $failed->retryState());
    }

    public function testMaxAttemptsCountsTotalAttemptsInTheHarness(): void
    {
        $runs = 0;
        $env = WorkflowTestEnvironment::inMemory([
            'flaky' => static function () use (&$runs): never {
                ++$runs;

                throw new \RuntimeException('boom');
            },
        ]);

        $options = new ActivityOptions(RetryLimit::ofAttempts(3), initialInterval: Duration::seconds(0.0));

        try {
            $env->run(static fn(WorkflowEnvironment $wf): mixed
                => $wf->await($wf->activityStub(SuiteActivities::class, $options)->flaky()), 'exec-3');
        } catch (\Throwable) {
        }

        self::assertSame(3, $runs);
        self::assertTrue($this->firstOf($env, 'exec-3', ActivityFailed::class)?->isStalled());
    }

    public function testChildWorkflowsRunInTheHarness(): void
    {
        $env = WorkflowTestEnvironment::inMemory(['work' => static fn(): string => 'from-activity']);
        $env->registerWorkflowClass(WorkingChild::class);

        $result = $env->run(static fn(WorkflowEnvironment $wf): string
            => 'parent[' . $wf->await($wf->childWorkflowStub(WorkingChild::class)->run()) . ']', 'parent-1');

        self::assertSame('parent[child(from-activity)]', $result);
        self::assertNotNull($this->firstOf($env, 'parent-1', ChildWorkflowCompleted::class));
    }

    public function testParentClosePolicyCascadesInTheHarness(): void
    {
        $env = WorkflowTestEnvironment::inMemory([]);
        // L'enfant reste bloqué sur une condition que rien ne peut satisfaire : le runner le
        // signale (WorkflowStuckException), son journal reste sans issue terminale, il est donc
        // encore actif à la clôture du parent.
        $env->registerWorkflowClass(PendingChild::class);

        $env->run(static function (WorkflowEnvironment $wf): string {
            // Démarré et jamais attendu : c'est le sujet du test, et c'est justement ce qu'un
            // stub qui attendait pour l'appelant ne savait pas exprimer.
            $wf->childWorkflowStub(PendingChild::class, new ChildWorkflowOptions(
                parentClosePolicy: ParentClosePolicy::Terminate,
            ))->run();

            return 'parent-done';
        }, 'parent-2');

        $scheduled = null;
        foreach ($env->getEventStore()->readStream('parent-2') as $event) {
            if ($event instanceof \Gplanchat\Durable\Event\ChildWorkflowScheduled) {
                $scheduled = $event->childExecutionId();
            }
        }
        self::assertNotNull($scheduled);

        $terminated = $this->firstOf($env, $scheduled, WorkflowExecutionFailed::class);
        self::assertNotNull($terminated, 'ParentClosePolicy::Terminate doit clôturer l’enfant encore actif');
        self::assertSame(WorkflowExecutionFailed::KIND_TERMINATED_BY_PARENT, $terminated->kind());
    }

    public function testRunnerReportsAWorkflowItCannotAdvanceInsteadOfLooping(): void
    {
        $env = WorkflowTestEnvironment::inMemory([]);

        $this->expectException(WorkflowStuckException::class);
        $this->expectExceptionMessageMatches('/waiting on condition at/');

        $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await(static fn(): bool => false), 'exec-stuck');
    }

    // -------------------------------------------------------------------------

    /** @return list<string> */
    private function shortNames(WorkflowTestEnvironment $env, string $executionId): array
    {
        $out = [];
        foreach ($env->getEventStore()->readStream($executionId) as $event) {
            $out[] = (new \ReflectionClass($event))->getShortName();
        }

        return $out;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function firstOf(WorkflowTestEnvironment $env, string $executionId, string $class): ?object
    {
        foreach ($env->getEventStore()->readStream($executionId) as $event) {
            if ($event instanceof $class) {
                return $event;
            }
        }

        return null;
    }
}

#[\Gplanchat\Durable\Attribute\Workflow(name: 'Child')]
final class WorkingChild
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[\Gplanchat\Durable\Attribute\WorkflowMethod]
    public function run(): string
    {
        return 'child(' . $this->environment->await(
            $this->environment->activityStub(SuiteActivities::class)->work(),
        ) . ')';
    }
}

/**
 * Reste bloqué sur une condition que rien ne peut satisfaire : son journal n'a pas d'issue
 * terminale, il est donc encore actif à la clôture du parent.
 */
#[\Gplanchat\Durable\Attribute\Workflow(name: 'Pending')]
final class PendingChild
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[\Gplanchat\Durable\Attribute\WorkflowMethod]
    public function run(): mixed
    {
        return $this->environment->await(static fn (): bool => false);
    }
}
