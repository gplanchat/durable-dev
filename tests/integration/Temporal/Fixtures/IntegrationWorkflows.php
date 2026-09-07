<?php

declare(strict_types=1);

namespace integration\Temporal\Fixtures;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;

/**
 * Workflows and activities shared between the test process and the worker processes.
 *
 * The workers run in separate processes — as in production — so they cannot receive closures
 * defined inside the test.
 */
final class IntegrationWorkflows
{
    private const ACTIVITY_TIMEOUT_SECONDS = 10.0;

    private function __construct() {}

    public static function registerActivities(RegistryActivityExecutor $executor): void
    {
        $executor->register('double', static fn(array $p): int => ((int) ($p['value'] ?? 0)) * 2);
        $executor->register('append', static fn(array $p): string => ((string) ($p['text'] ?? '')) . '!');
        $executor->register('refund', static fn(array $p): string => 'refunded:' . ($p['order'] ?? '?'));
        $executor->register('boom', static function (array $p): never {
            throw new \DomainException('activity exploded');
        });
    }

    public static function registerWorkflows(WorkflowRegistry $registry): void
    {
        $registry->registerFactory('Plain', static fn(array $input) => static fn(WorkflowEnvironment $env): array => ['echo' => $input['value'] ?? null]);

        // A class rather than a factory: that is what a child stub knows how to resolve, and
        // `registerClass()` registers it under its alias as well as under its FQCN — the tests
        // that start it by "Doubler" do not change.
        $registry->registerClass(DoublerWorkflow::class);

        $registry->registerFactory('TwoActivities', static fn(array $input) => static function (WorkflowEnvironment $env) use ($input): array {
            $doubled = $env->await($env->activityStub(IntegrationActivities::class, self::options())->double((int) ($input['value'] ?? 0)));

            return ['text' => $env->await($env->activityStub(IntegrationActivities::class, self::options())->append((string) $doubled))];
        });

        // One cron run: it must finish for the server to schedule the next one.
        $registry->registerFactory('Ticking', static fn(array $input) => static fn(WorkflowEnvironment $env): array => ['tick' => $env->await($env->activityStub(
            IntegrationActivities::class,
            self::options(),
        )->double((int) ($input['value'] ?? 1)))]);

        // The case no fake server can settle: the signal is delivered *after* the deadline fired,
        // and every workflow task replays everything from the start — if the verdict came from
        // anywhere but the journal order, the replay would read the opposite (ADR DUR032).
        $registry->registerFactory('SignalDeadline', static fn(array $input) => static function (WorkflowEnvironment $env): array {
            $approvals = [];
            $env->onSignal('approve', static function (array $payload) use (&$approvals): void {
                $approvals[] = $payload;
            });
            $pending = static function () use (&$approvals): bool {
                return [] !== $approvals;
            };

            try {
                $env->await($pending, Duration::seconds(2));
                $first = ['signal', array_shift($approvals)];
            } catch (DeadlineExceededException) {
                $first = ['timeout'];
            }

            // Leaves the late signal the time to be recorded while the execution is still
            // open.
            $env->sleep(Duration::seconds(5));

            try {
                $env->await($pending, Duration::seconds(10));
                $second = ['signal', array_shift($approvals)];
            } catch (DeadlineExceededException) {
                $second = ['timeout'];
            }

            return ['first' => $first, 'second' => $second];
        });

        // An update that answers: the handler's return value *is* the caller's answer, and it
        // unblocks at the same time the condition the body is waiting on.
        $registry->registerFactory('Updatable', static fn(array $input) => static function (WorkflowEnvironment $env): array {
            $answer = null;
            $env->onUpdate('approve', static function (array $args) use (&$answer): array {
                $answer = ['ok' => true, 'by' => $args['by'] ?? '?'];

                return $answer;
            });
            $env->onUpdate('refuse', static function (array $args): never {
                throw new \DomainException('approbation refusée');
            });

            $env->await(static function () use (&$answer): bool {
                return null !== $answer;
            });

            return ['approved' => $answer];
        });

        $registry->registerFactory('Sleeper', static fn(array $input) => static function (WorkflowEnvironment $env): array {
            $env->sleep(1.0);

            return ['slept' => true];
        });

        $registry->registerFactory('SideEffecting', static fn(array $input) => static function (WorkflowEnvironment $env) use ($input): array {
            return ['side' => $env->sideEffect(static fn(): int => ((int) ($input['seed'] ?? 0)) + 1)];
        });

        // A bounded maxAttempts: without it the server applies its default RetryPolicy and
        // retries indefinitely — the workflow would never fail.
        $registry->registerFactory('FailsOnActivity', static fn(array $input) => static fn(WorkflowEnvironment $env): mixed => $env->await($env->activityStub(IntegrationActivities::class, new ActivityOptions(
            RetryLimit::once(),
            timeouts: self::attemptTimeout(),
        ))->boom()));

        $registry->registerFactory('UnboundedRetry', static fn(array $input) => static fn(WorkflowEnvironment $env): mixed => $env->await($env->activityStub(IntegrationActivities::class, self::options())->boom()));

        $registry->registerFactory('NonRetryable', static fn(array $input) => static fn(WorkflowEnvironment $env): mixed => $env->await($env->activityStub(IntegrationActivities::class, new ActivityOptions(
            RetryLimit::ofAttempts(5),
            initialInterval: Duration::seconds(0.1),
            nonRetryableExceptions: [\DomainException::class],
            timeouts: self::attemptTimeout(),
        ))->boom()));

        $registry->registerFactory('Compensating', static fn(array $input) => static function (WorkflowEnvironment $env) use ($input): mixed {
            try {
                return $env->await($env->activityStub(IntegrationActivities::class, new ActivityOptions(
                    timeouts: ActivityTimeouts::attempt(Duration::seconds(60.0)),
                ))->double(1));
            } catch (WorkflowCancelledFailure $e) {
                $env->await($env->activityStub(IntegrationActivities::class, self::options())->refund((string) ($input['order'] ?? 'x')));

                throw $e;
            }
        });

        // Both branches must leave in the SAME workflow task: that is what going from N fiber
        // suspensions down to a single one must not have changed (ADR DUR033).
        $registry->registerFactory('Assembled', static fn(array $input) => static fn(WorkflowEnvironment $env): array => ['both' => $env->await($env->all(
            $env->activityStub(IntegrationActivities::class, self::options())->double((int) ($input['value'] ?? 0)),
            $env->activityStub(IntegrationActivities::class, self::options())->append('x'),
        ))]);

        // Quorum reached by the activities; the losing timers must be removed on the server
        // side, failing which the execution would wait an hour.
        $registry->registerFactory('Quorum', static fn(array $input) => static function (WorkflowEnvironment $env): array {
            $reached = $env->await($env->some(
                2,
                $env->activityStub(IntegrationActivities::class, self::options())->double(1),
                $env->activityStub(IntegrationActivities::class, self::options())->double(2),
                $env->timer(Duration::hours(1), 'loser-1'),
                $env->timer(Duration::hours(2), 'loser-2'),
            ));

            return ['keys' => array_keys($reached), 'values' => array_values($reached)];
        });

        $registry->registerFactory('ChildParent', static fn(array $input) => static function (WorkflowEnvironment $env) use ($input): array {
            return ['fromChild' => $env->await(
                $env->childWorkflowStub(DoublerWorkflow::class)->run((int) ($input['value'] ?? 0)),
            )];
        });
    }

    /** Exposed for {@see DoublerWorkflow}, which lives outside this class. */
    public static function stubOptions(): ActivityOptions
    {
        return self::options();
    }

    /**
     * The same workflow type, two bodies, chosen by the worker variant.
     *
     * `default` schedules `double` at activity slot 0; `divergent` schedules `append` there. An
     * execution started on one and then resumed by the other is exactly what a deployment does to
     * an in-flight execution — and the only rig that puts the DUR042 guard under a real server.
     *
     * The timer between the two opens the window: that is where the worker gets replaced.
     */
    public static function registerDivergentPair(WorkflowRegistry $registry, string $variant): void
    {
        $registry->registerFactory('DivergentByDeploy', static fn(array $input) => static function (WorkflowEnvironment $env) use ($input, $variant): array {
            $stub = $env->activityStub(IntegrationActivities::class, self::options());
            $first = 'divergent' === $variant
                ? $env->await($stub->append('deployed-v2'))
                : $env->await($stub->double((int) ($input['value'] ?? 21)));

            $env->sleep(Duration::seconds((float) (getenv('DURABLE_DIVERGENCE_WINDOW') ?: 12)));

            return ['variant' => $variant, 'slot0' => $first];
        });
    }

    private static function options(): ActivityOptions
    {
        return new ActivityOptions(timeouts: self::attemptTimeout());
    }

    private static function attemptTimeout(): ActivityTimeouts
    {
        return ActivityTimeouts::attempt(Duration::seconds(self::ACTIVITY_TIMEOUT_SECONDS));
    }
}

#[\Gplanchat\Durable\Attribute\AsWorkflow(name: 'Doubler')]
final class DoublerWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    /**
     * @return array{doubled: mixed}
     */
    #[\Gplanchat\Durable\Attribute\AsWorkflowMethod]
    public function run(int $value = 0): array
    {
        return ['doubled' => $this->environment->await(
            $this->environment->activityStub(IntegrationActivities::class, IntegrationWorkflows::stubOptions())->double($value),
        )];
    }
}
