<?php

declare(strict_types=1);

namespace integration\Temporal\Fixtures;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;

/**
 * Workflows et activités partagés entre le processus de test et les processus worker.
 *
 * Les workers tournent dans des processus séparés — comme en production —, ils ne peuvent donc
 * pas recevoir de closures définies dans le test.
 */
final class IntegrationWorkflows
{
    public const ACTIVITY_TIMEOUT = 10.0;

    private function __construct()
    {
    }

    public static function registerActivities(RegistryActivityExecutor $executor): void
    {
        $executor->register('double', static fn (array $p): int => ((int) ($p['value'] ?? 0)) * 2);
        $executor->register('append', static fn (array $p): string => ((string) ($p['text'] ?? '')).'!');
        $executor->register('refund', static fn (array $p): string => 'refunded:'.($p['order'] ?? '?'));
        $executor->register('boom', static function (array $p): never {
            throw new \DomainException('activity exploded');
        });
    }

    public static function registerWorkflows(WorkflowRegistry $registry): void
    {
        $registry->registerFactory('Plain', static fn (array $input) => static fn (WorkflowEnvironment $env): array => ['echo' => $input['value'] ?? null]);

        $registry->registerFactory('Doubler', static fn (array $input) => static fn (WorkflowEnvironment $env): array => ['doubled' => $env->await($env->activity(
            'double',
            ['value' => $input['value'] ?? 0],
            self::options(),
        ))]);

        $registry->registerFactory('TwoActivities', static fn (array $input) => static function (WorkflowEnvironment $env) use ($input): array {
            $doubled = $env->await($env->activity('double', ['value' => $input['value'] ?? 0], self::options()));

            return ['text' => $env->await($env->activity('append', ['text' => (string) $doubled], self::options()))];
        });

        $registry->registerFactory('Sleeper', static fn (array $input) => static function (WorkflowEnvironment $env): array {
            $env->timer(1.0);

            return ['slept' => true];
        });

        $registry->registerFactory('SideEffecting', static fn (array $input) => static function (WorkflowEnvironment $env) use ($input): array {
            return ['side' => $env->sideEffect(static fn (): int => ((int) ($input['seed'] ?? 0)) + 1)];
        });

        // maxAttempts borné : sans lui le serveur applique sa RetryPolicy par défaut et retente
        // indéfiniment — le workflow n'échouerait jamais.
        $registry->registerFactory('FailsOnActivity', static fn (array $input) => static fn (WorkflowEnvironment $env): mixed => $env->await($env->activity('boom', [], new ActivityOptions(
            maxAttempts: 1,
            startToCloseTimeoutSeconds: self::ACTIVITY_TIMEOUT,
        ))));

        $registry->registerFactory('UnboundedRetry', static fn (array $input) => static fn (WorkflowEnvironment $env): mixed => $env->await($env->activity('boom', [], self::options())));

        $registry->registerFactory('NonRetryable', static fn (array $input) => static fn (WorkflowEnvironment $env): mixed => $env->await($env->activity('boom', [], new ActivityOptions(
            maxAttempts: 5,
            initialIntervalSeconds: 0.1,
            nonRetryableExceptions: [\DomainException::class],
            startToCloseTimeoutSeconds: self::ACTIVITY_TIMEOUT,
        ))));

        $registry->registerFactory('Compensating', static fn (array $input) => static function (WorkflowEnvironment $env) use ($input): mixed {
            try {
                return $env->await($env->activity('double', ['value' => 1], new ActivityOptions(
                    startToCloseTimeoutSeconds: 60.0,
                    scheduleToStartTimeoutSeconds: 60.0,
                )));
            } catch (WorkflowCancelledFailure $e) {
                $env->await($env->activity('refund', ['order' => $input['order'] ?? 'x'], self::options()));

                throw $e;
            }
        });

        $registry->registerFactory('ChildParent', static fn (array $input) => static function (WorkflowEnvironment $env) use ($input): array {
            return ['fromChild' => $env->executeChildWorkflow('Doubler', ['value' => $input['value'] ?? 0])];
        });
    }

    private static function options(): ActivityOptions
    {
        return new ActivityOptions(startToCloseTimeoutSeconds: self::ACTIVITY_TIMEOUT);
    }
}
