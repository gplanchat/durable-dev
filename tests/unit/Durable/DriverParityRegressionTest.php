<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Awaitable\AwaitableInspector;
use Gplanchat\Durable\Event\ActivityCompleted;
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
            $env->run(static fn (WorkflowEnvironment $wf): mixed => $wf->await($wf->activity(
                'flaky',
                [],
                new ActivityOptions(maxAttempts: 3, initialIntervalSeconds: 0.01),
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
            ['retry_delay_seconds' => 30.0],
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
        $executor->register('slow', static fn (): string => 'unused');
        $engine = new ExecutionEngine(
            $eventStore,
            new ExecutionRuntime($eventStore, $transport, $executor, 0, null, true),
        );

        try {
            $engine->start('race-1', static fn (WorkflowEnvironment $env): mixed => $env->any(
                $env->activity('slow', []),
                $env->timerAwaitable(3600.0),
            ));
            self::fail('le workflow devait suspendre');
        } catch (WorkflowSuspendedException $e) {
            self::assertTrue($e->waitingOnTimer(), 'la course porte une échéance à réveiller');
            self::assertTrue($e->shouldDispatchResume());
        }
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

    public function testSyncDrainStillCompletesASimpleActivity(): void
    {
        $env = WorkflowTestEnvironment::inMemory(['echo' => static fn (array $p): mixed => $p['v']]);

        self::assertSame(42, $env->run(
            static fn (WorkflowEnvironment $wf): mixed => $wf->await($wf->activity('echo', ['v' => 42])),
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
