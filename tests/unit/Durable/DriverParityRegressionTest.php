<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Awaitable\AwaitableInspector;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Exception\WorkflowStuckException;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Deux régressions du passage au chemin d'activité unique et au pilote de fiber unique.
 */
final class DriverParityRegressionTest extends TestCase
{
    public function testDelayedRetriesAreExecutedByTheSynchronousDrain(): void
    {
        // isEmpty() ne signale que l'absence de message *prêt* : boucler dessus concluait
        // « plus rien à faire » et la politique de retry ne s'appliquait pas du tout.
        $runs = 0;
        $env = WorkflowTestEnvironment::inMemory([
            'flaky' => static function () use (&$runs): never {
                ++$runs;

                throw new \RuntimeException('boom');
            },
        ]);

        try {
            $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->activity(
                'flaky',
                [],
                new ActivityOptions(RetryLimit::ofAttempts(3), initialInterval: Duration::seconds(0.01)),
            )), 'retry-1');
        } catch (\Throwable) {
        }

        self::assertSame(3, $runs, 'les retentatives différées doivent être exécutées');
    }

    public function testDelayedRetryIsNotDequeuedBeforeItsDueTime(): void
    {
        // Le backoff est réellement respecté : une retentative n'est pas consommée d'avance.
        $transport = new InMemoryActivityTransport();
        $transport->enqueue(new \Gplanchat\Durable\Transport\ActivityMessage(
            'exec-1',
            'act-1',
            'later',
            [],
            retryDelay: Duration::seconds(30),
        ));

        self::assertNull($transport->dequeue());
        self::assertTrue($transport->isEmpty(), 'aucun message prêt');
        self::assertNotNull($transport->nextDueAt(), 'mais la file n’est pas vide pour autant');
    }

    public function testARaceBetweenAnActivityAndATimerSchedulesATimerWake(): void
    {
        // Testé par un simple instanceof, un any(activity, timer) ne planifiait aucun réveil :
        // l'exécution ne repartait jamais si l'activité n'aboutissait pas.
        $eventStore = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $executor = new RegistryActivityExecutor();
        $executor->register('slow', static fn(): string => 'unused');
        $engine = new ExecutionEngine(
            $eventStore,
            new ExecutionRuntime($eventStore, $transport, $executor, 0, null, true),
        );

        try {
            $engine->start('race-1', static fn(WorkflowEnvironment $env): mixed => $env->await($env->any(
                $env->activity('slow', []),
                $env->timer(3600.0),
            )));
            self::fail('le workflow devait suspendre');
        } catch (WorkflowSuspendedException $e) {
            self::assertTrue($e->waitingOnTimer(), 'la course porte une échéance à réveiller');
            self::assertTrue($e->shouldDispatchResume());
        }
    }

    public function testSleepWaitsWhileTimerComposes(): void
    {
        // Deux méthodes voisines de la même façade avaient des contrats opposés : activity()
        // rendait un awaitable, timer() attendait sur place et rendait void. Les noms disent
        // maintenant lequel fait quoi.
        $env = WorkflowTestEnvironment::inMemory(['echo' => static fn(): string => 'done']);

        $result = $env->run(static function (WorkflowEnvironment $wf): array {
            $composable = $wf->timer(0.0);
            $wf->sleep(0.0);

            return [$composable instanceof \Gplanchat\Durable\Awaitable\Awaitable, $wf->await($wf->activity('echo', []))];
        }, 'sleep-1');

        self::assertSame([true, 'done'], $result);
    }

    public function testTimerAcceptsEveryDurationForm(): void
    {
        $env = WorkflowTestEnvironment::inMemory([]);

        $result = $env->run(static function (WorkflowEnvironment $wf): string {
            $wf->sleep(30.0);
            $wf->sleep(Duration::minutes(5));
            $wf->sleep(new \DateInterval('PT2H'));

            return 'ok';
        }, 'sleep-2');

        self::assertSame('ok', $result);
    }

    public function testTheHarnessSkipsTimeInsteadOfWaitingForIt(): void
    {
        // Sans saut d'horloge, aucun workflow qui dort n'est testable : le harnais n'a personne
        // pour lui livrer un réveil de minuteur.
        $env = WorkflowTestEnvironment::inMemory(['ping' => static fn(): string => 'pong']);

        $startedAt = microtime(true);
        $result = $env->run(static function (WorkflowEnvironment $wf): string {
            $wf->sleep(Duration::hours(1));
            $answer = $wf->await($wf->activity('ping', []));
            $wf->sleep(Duration::hours(24));

            return $answer;
        }, 'skip-1');

        self::assertSame('pong', $result);
        self::assertLessThan(1.0, microtime(true) - $startedAt, '25 heures de sommeil ne doivent coûter aucun temps réel');
    }

    public function testTimeIsNotSkippedWhileAnActivityCanStillWin(): void
    {
        // Le saut ne doit intervenir que lorsque plus rien d'autre ne progresse : sauter plus tôt
        // ferait gagner le minuteur de toute course qu'une activité était en train de remporter.
        $env = WorkflowTestEnvironment::inMemory(['fast' => static fn(): string => 'winner']);

        $result = $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->any(
            $wf->activity('fast', []),
            $wf->timer(Duration::hours(1)),
        )), 'race-skip');

        self::assertSame('winner', $result);
    }

    public function testTimerWaitDetectionTraversesComposites(): void
    {
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        $activity = new \Gplanchat\Durable\Awaitable\ActivityAwaitable($deferred->awaitable(), 'act-1');
        $timer = new \Gplanchat\Durable\Awaitable\TimerAwaitable($deferred->awaitable(), 'timer-1');

        self::assertFalse(AwaitableInspector::waitsOnTimer($activity));
        self::assertTrue(AwaitableInspector::waitsOnTimer($timer));
        self::assertTrue(AwaitableInspector::waitsOnTimer(
            new \Gplanchat\Durable\Awaitable\AnyAwaitable([$activity, $timer]),
        ));
        self::assertFalse(AwaitableInspector::waitsOnTimer(
            new \Gplanchat\Durable\Awaitable\AnyAwaitable([$activity, $activity]),
        ));
    }

    public function testTheHarnessReportsAnActivityThatRetriesForever(): void
    {
        // Les tentatives étant désormais illimitées par défaut, le harnais doit échouer avec un
        // message actionnable au lieu de tourner sans fin.
        $env = WorkflowTestEnvironment::inMemory(
            ['always' => static function (): never {
                throw new \RuntimeException('boom');
            }],
            budgetSeconds: 0.5,
        );

        $this->expectException(WorkflowStuckException::class);
        $this->expectExceptionMessageMatches('/retry indefinitely by default/');

        $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->activity('always', [], new ActivityOptions(initialInterval: Duration::seconds(0.05)))),
            'runaway-1',
        );
    }

    public function testSyncDrainStillCompletesASimpleActivity(): void
    {
        $env = WorkflowTestEnvironment::inMemory(['echo' => static fn(array $p): mixed => $p['v']]);

        self::assertSame(42, $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->activity('echo', ['v' => 42])),
            'plain-1',
        ));
        $completed = null;
        foreach ($env->getEventStore()->readStream('plain-1') as $event) {
            if ($event instanceof ActivityCompleted) {
                $completed = $event;
            }
        }
        self::assertSame(42, $completed?->result());
    }
}
