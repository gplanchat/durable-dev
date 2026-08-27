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

        // Une classe et non une fabrique : c'est ce qu'un stub d'enfant sait résoudre, et
        // `registerClass()` l'enregistre sous son alias comme sous son FQCN — les tests qui la
        // démarrent par « Doubler » ne changent pas.
        $registry->registerClass(DoublerWorkflow::class);

        $registry->registerFactory('TwoActivities', static fn(array $input) => static function (WorkflowEnvironment $env) use ($input): array {
            $doubled = $env->await($env->activityStub(IntegrationActivities::class, self::options())->double((int) ($input['value'] ?? 0)));

            return ['text' => $env->await($env->activityStub(IntegrationActivities::class, self::options())->append((string) $doubled))];
        });

        // Un run de cron : il doit se terminer pour que le serveur planifie le suivant.
        $registry->registerFactory('Ticking', static fn(array $input) => static fn(WorkflowEnvironment $env): array => ['tick' => $env->await($env->activityStub(
            IntegrationActivities::class,
            self::options(),
        )->double((int) ($input['value'] ?? 1)))]);

        // Le cas qu'aucun faux serveur ne peut trancher : le signal est livré *après* le tir de
        // l'échéance, et chaque tâche de workflow rejoue tout depuis le début — si le verdict
        // venait d'ailleurs que de l'ordre du journal, le replay lirait l'inverse (ADR DUR032).
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

            // Laisse au signal en retard le temps d'être enregistré pendant que l'exécution est
            // encore ouverte.
            $env->sleep(Duration::seconds(5));

            try {
                $env->await($pending, Duration::seconds(10));
                $second = ['signal', array_shift($approvals)];
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

        // Les deux branches doivent partir dans la MÊME workflow task : c'est ce que le passage
        // de N suspensions de fiber à une seule ne doit pas avoir changé (ADR DUR033).
        $registry->registerFactory('Assembled', static fn(array $input) => static fn(WorkflowEnvironment $env): array => ['both' => $env->await($env->all(
            $env->activityStub(IntegrationActivities::class, self::options())->double((int) ($input['value'] ?? 0)),
            $env->activityStub(IntegrationActivities::class, self::options())->append('x'),
        ))]);

        // Quorum atteint par les activités ; les minuteurs perdants doivent être retirés côté
        // serveur, sans quoi l'exécution attendrait une heure.
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

    /** Exposé pour {@see DoublerWorkflow}, qui vit hors de cette classe. */
    public static function stubOptions(): ActivityOptions
    {
        return self::options();
    }

    /**
     * Le même type de workflow, deux corps, choisis par la variante du worker.
     *
     * `default` planifie `double` au slot d'activité 0 ; `divergent` y planifie `append`. Une
     * exécution démarrée sur l'un puis reprise par l'autre est exactement ce qu'un déploiement fait
     * à une exécution en vol — et le seul montage qui met la garde de DUR042 sous un vrai serveur.
     *
     * Le minuteur entre les deux ouvre la fenêtre : c'est là qu'on remplace le worker.
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

#[\Gplanchat\Durable\Attribute\Workflow(name: 'Doubler')]
final class DoublerWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    /**
     * @return array{doubled: mixed}
     */
    #[\Gplanchat\Durable\Attribute\WorkflowMethod]
    public function run(int $value = 0): array
    {
        return ['doubled' => $this->environment->await(
            $this->environment->activityStub(IntegrationActivities::class, IntegrationWorkflows::stubOptions())->double($value),
        )];
    }
}
