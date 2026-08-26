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
 * Workflows et activités partagés entre le processus de test et les processus worker.
 *
 * Les workers tournent dans des processus séparés — comme en production —, ils ne peuvent donc
 * pas recevoir de closures définies dans le test.
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

        $registry->registerFactory('Doubler', static fn(array $input) => static fn(WorkflowEnvironment $env): array => ['doubled' => $env->await($env->activity(
            'double',
            ['value' => $input['value'] ?? 0],
            self::options(),
        ))]);

        $registry->registerFactory('TwoActivities', static fn(array $input) => static function (WorkflowEnvironment $env) use ($input): array {
            $doubled = $env->await($env->activity('double', ['value' => $input['value'] ?? 0], self::options()));

            return ['text' => $env->await($env->activity('append', ['text' => (string) $doubled], self::options()))];
        });

        // Un run de cron : il doit se terminer pour que le serveur planifie le suivant.
        $registry->registerFactory('Ticking', static fn(array $input) => static fn(WorkflowEnvironment $env): array => ['tick' => $env->await($env->activity(
            'double',
            ['value' => $input['value'] ?? 1],
            self::options(),
        ))]);

        // Le cas qu'aucun faux serveur ne peut trancher : le signal est livré *après* le tir de
        // l'échéance, et chaque tâche de workflow rejoue tout depuis le début — si le verdict
        // venait d'ailleurs que de l'ordre du journal, le replay lirait l'inverse (ADR DUR032).
        $registry->registerFactory('SignalDeadline', static fn(array $input) => static function (WorkflowEnvironment $env): array {
            try {
                $first = ['signal', $env->waitSignal('approve', Duration::seconds(2))];
            } catch (DeadlineExceededException) {
                $first = ['timeout'];
            }

            // Laisse au signal en retard le temps d'être enregistré pendant que l'exécution est
            // encore ouverte.
            $env->sleep(Duration::seconds(5));

            try {
                $second = ['signal', $env->waitSignal('approve', Duration::seconds(10))];
            } catch (DeadlineExceededException) {
                $second = ['timeout'];
            }

            return ['first' => $first, 'second' => $second];
        });

        // Un update qui répond : le retour du handler *est* la réponse de l'appelant, et il
        // débloque en même temps la condition que le corps attend.
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

        // maxAttempts borné : sans lui le serveur applique sa RetryPolicy par défaut et retente
        // indéfiniment — le workflow n'échouerait jamais.
        $registry->registerFactory('FailsOnActivity', static fn(array $input) => static fn(WorkflowEnvironment $env): mixed => $env->await($env->activity('boom', [], new ActivityOptions(
            RetryLimit::once(),
            timeouts: self::attemptTimeout(),
        ))));

        $registry->registerFactory('UnboundedRetry', static fn(array $input) => static fn(WorkflowEnvironment $env): mixed => $env->await($env->activity('boom', [], self::options())));

        $registry->registerFactory('NonRetryable', static fn(array $input) => static fn(WorkflowEnvironment $env): mixed => $env->await($env->activity('boom', [], new ActivityOptions(
            RetryLimit::ofAttempts(5),
            initialInterval: Duration::seconds(0.1),
            nonRetryableExceptions: [\DomainException::class],
            timeouts: self::attemptTimeout(),
        ))));

        $registry->registerFactory('Compensating', static fn(array $input) => static function (WorkflowEnvironment $env) use ($input): mixed {
            try {
                return $env->await($env->activity('double', ['value' => 1], new ActivityOptions(
                    timeouts: ActivityTimeouts::attempt(Duration::seconds(60.0)),
                )));
            } catch (WorkflowCancelledFailure $e) {
                $env->await($env->activity('refund', ['order' => $input['order'] ?? 'x'], self::options()));

                throw $e;
            }
        });

        // Les deux branches doivent partir dans la MÊME workflow task : c'est ce que le passage
        // de N suspensions de fiber à une seule ne doit pas avoir changé (ADR DUR033).
        $registry->registerFactory('Assembled', static fn(array $input) => static fn(WorkflowEnvironment $env): array => ['both' => $env->await($env->all(
            $env->activity('double', ['value' => $input['value'] ?? 0], self::options()),
            $env->activity('append', ['text' => 'x'], self::options()),
        ))]);

        // Quorum atteint par les activités ; les minuteurs perdants doivent être retirés côté
        // serveur, sans quoi l'exécution attendrait une heure.
        $registry->registerFactory('Quorum', static fn(array $input) => static function (WorkflowEnvironment $env): array {
            $reached = $env->await($env->some(
                2,
                $env->activity('double', ['value' => 1], self::options()),
                $env->activity('double', ['value' => 2], self::options()),
                $env->timer(Duration::hours(1), 'loser-1'),
                $env->timer(Duration::hours(2), 'loser-2'),
            ));

            return ['keys' => array_keys($reached), 'values' => array_values($reached)];
        });

        $registry->registerFactory('ChildParent', static fn(array $input) => static function (WorkflowEnvironment $env) use ($input): array {
            return ['fromChild' => $env->executeChildWorkflow('Doubler', ['value' => $input['value'] ?? 0])];
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
