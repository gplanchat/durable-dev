<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\Exception\WorkflowStuckException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Attendre une condition sur l'état du workflow — bloc 2 du change
 * workflow-conditions-and-handler-dispatch.
 *
 * Les cas de verdict sont joués sur un journal écrit à la main puis rejoué : c'est le seul moyen
 * d'atteindre l'ordre d'événements qui compte, et c'est le chemin de replay que le verdict doit
 * traverser.
 *
 * Note aux relectures : ces tests sont ROUGES par construction. `await(condition)` et
 * `onSignal()` n'existent pas encore — ils arrivent aux blocs 4 et 5. Ce fichier fixe la forme
 * publique visée, pas une régression.
 */
final class WorkflowConditionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // 2.1 — une condition déjà vraie
    // -------------------------------------------------------------------------

    public function testAConditionThatAlreadyHoldsDoesNotSuspend(): void
    {
        $env = WorkflowTestEnvironment::inMemory([]);

        $result = $env->run(static function (WorkflowEnvironment $wf): string {
            $wf->await(static fn(): bool => true);

            return 'passé sans suspendre';
        }, 'cond-1');

        self::assertSame('passé sans suspendre', $result);
        // Rien qui puisse réveiller l'exécution plus tard : pas de minuteur de garde planifié.
        self::assertSame([], $this->eventsOf($env->getEventStore(), 'cond-1', TimerScheduled::class));
    }

    // -------------------------------------------------------------------------
    // 2.2 / 2.3 — un message rend la condition vraie, et le replay repart au même endroit
    // -------------------------------------------------------------------------

    public function testAConditionBecomesTrueOnADeliveredMessage(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);

        $store->append(new ExecutionStarted('cond-2', []));
        $store->append(new WorkflowSignalReceived('cond-2', 'tick', ['n' => 1]));

        self::assertSame([['n' => 1]], $engine->resume('cond-2', $this->tickHandler(1)));
    }

    public function testReplayReachesTheSameStateAndSchedulesNothingNew(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);
        $handler = $this->tickHandler(1);

        $store->append(new ExecutionStarted('cond-3', []));
        $store->append(new WorkflowSignalReceived('cond-3', 'tick', ['n' => 1]));

        $first = $engine->resume('cond-3', $handler);
        $second = $engine->resume('cond-3', $handler);

        self::assertSame($first, $second);
        // « rien de neuf » porte sur le travail planifié, pas sur le journal entier : rejouer une
        // exécution déjà close y réécrit sa clôture, et c'est vrai de tout replay, condition ou pas.
        self::assertSame([], $this->eventsOf($store, 'cond-3', TimerScheduled::class));
        self::assertCount(1, $this->eventsOf($store, 'cond-3', WorkflowSignalReceived::class));
    }

    // -------------------------------------------------------------------------
    // 2.4 — la garantie DUR032, réénoncée sur une condition
    // -------------------------------------------------------------------------

    public function testAMessageRecordedAfterTheDeadlineDoesNotUndoTheTimeout(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);

        $store->append(new ExecutionStarted('cond-4', []));
        $store->append(new TimerScheduled('cond-4', 'timer-a', 0.0));
        $store->append(new TimerCompleted('cond-4', 'timer-a'));
        $store->append(new WorkflowSignalReceived('cond-4', 'tick', ['n' => 1]));

        self::assertSame(['expiré'], $engine->resume('cond-4', $this->boundedTickHandler()));
        self::assertSame(['expiré'], $engine->resume('cond-4', $this->boundedTickHandler()), 'stable au replay');
    }

    public function testAMessageRecordedBeforeTheDeadlineStillSatisfiesTheCondition(): void
    {
        // Les deux branches sont réglées dans l'historique et aucune n'est annulée : seul l'ordre
        // du journal peut les départager.
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);

        $store->append(new ExecutionStarted('cond-5', []));
        $store->append(new TimerScheduled('cond-5', 'timer-a', 0.0));
        $store->append(new WorkflowSignalReceived('cond-5', 'tick', ['n' => 1]));
        $store->append(new TimerCompleted('cond-5', 'timer-a'));

        self::assertSame(['satisfait', ['n' => 1]], $engine->resume('cond-5', $this->boundedTickHandler()));
    }

    // -------------------------------------------------------------------------
    // 2.5 — les messages sont appliqués un par un
    // -------------------------------------------------------------------------

    public function testTwoMessagesAreAppliedOneAtATime(): void
    {
        // Le test discriminant de l'entrelacement : deux messages satisfont chacun la condition.
        // Appliqués d'un bloc, le workflow reprendrait en en voyant deux ; un par un, il reprend
        // sur le premier.
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);

        $store->append(new ExecutionStarted('cond-6', []));
        $store->append(new WorkflowSignalReceived('cond-6', 'tick', ['n' => 1]));
        $store->append(new WorkflowSignalReceived('cond-6', 'tick', ['n' => 2]));

        $seen = $engine->resume('cond-6', static function (WorkflowEnvironment $wf): array {
            $ticks = [];
            $wf->onSignal('tick', static function (array $payload) use (&$ticks): void {
                $ticks[] = $payload;
            });

            // `fn()` capture par valeur : une condition sur une variable locale doit passer
            // par `use (&$…)`, sans quoi elle relit éternellement l'état initial.
            $wf->await(static function () use (&$ticks): bool {
                return [] !== $ticks;
            });

            return ['au réveil' => \count($ticks)];
        });

        self::assertSame(['au réveil' => 1], $seen);
    }

    // -------------------------------------------------------------------------
    // 2.6 — une condition qui ne peut jamais devenir vraie
    // -------------------------------------------------------------------------

    public function testAConditionThatCanNeverHoldIsReportedNotHung(): void
    {
        $env = WorkflowTestEnvironment::inMemory([]);

        try {
            $env->run(static function (WorkflowEnvironment $wf): never {
                $wf->await(static fn(): bool => false);

                throw new \LogicException('inatteignable');
            }, 'cond-7');
            self::fail('l’exécution devait être signalée comme incapable d’avancer');
        } catch (WorkflowStuckException $e) {
            // « en nommant la condition » : sa position suffit à la retrouver, et n'ajoute
            // aucun paramètre à l'API.
            self::assertStringContainsString('WorkflowConditionTest.php', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // 2.7 — une valeur non reproductible se consigne avant d'être lue
    // -------------------------------------------------------------------------

    public function testANonReproducibleValueIsReadBackIdenticallyOnReplay(): void
    {
        $draws = 0;
        $env = WorkflowTestEnvironment::inMemory([]);
        $handler = static function (WorkflowEnvironment $wf) use (&$draws): int {
            $threshold = $wf->sideEffect(static function () use (&$draws): int {
                ++$draws;

                return 7;
            });

            $wf->await(static fn(): bool => $threshold > 0);

            return $threshold;
        };

        self::assertSame(7, $env->run($handler, 'cond-8'));
        self::assertSame(7, $env->run($handler, 'cond-8'), 'le replay relit la valeur consignée');
        self::assertSame(1, $draws, 'la closure non reproductible n’est évaluée qu’une fois');
    }

    // -------------------------------------------------------------------------

    /**
     * Workflow : accumule les signaux `tick` par un handler, et attend d'en avoir assez.
     *
     * @return callable(WorkflowEnvironment): array<int, mixed>
     */
    private function tickHandler(int $expected): callable
    {
        return static function (WorkflowEnvironment $wf) use ($expected): array {
            $ticks = [];
            $wf->onSignal('tick', static function (array $payload) use (&$ticks): void {
                $ticks[] = $payload;
            });

            $wf->await(static function () use (&$ticks, $expected): bool {
                return \count($ticks) >= $expected;
            });

            return $ticks;
        };
    }

    /**
     * Le même, sous échéance : la condition gagne, ou l'échéance.
     *
     * @return callable(WorkflowEnvironment): array<int, mixed>
     */
    private function boundedTickHandler(): callable
    {
        return static function (WorkflowEnvironment $wf): array {
            $ticks = [];
            $wf->onSignal('tick', static function (array $payload) use (&$ticks): void {
                $ticks[] = $payload;
            });

            try {
                $wf->await(static function () use (&$ticks): bool {
                    return [] !== $ticks;
                }, Duration::seconds(30));

                return ['satisfait', $ticks[0]];
            } catch (DeadlineExceededException) {
                return ['expiré'];
            }
        };
    }

    private function engine(InMemoryEventStore $store): ExecutionEngine
    {
        return new ExecutionEngine(
            $store,
            new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true),
        );
    }

    /**
     * @param class-string $class
     *
     * @return list<object>
     */
    private function eventsOf(InMemoryEventStore $store, string $executionId, string $class): array
    {
        $out = [];
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof $class) {
                $out[] = $event;
            }
        }

        return $out;
    }
}
