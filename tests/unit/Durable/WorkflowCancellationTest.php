<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\ActivityCancellationReason;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Exception\WorkflowCancelledException;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\ParentChildWorkflowCoordinator;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * L'annulation est livrée DANS le fiber, au point d'attente — équivalent du CanceledFailure
 * Temporal. Auparavant elle empêchait le fiber de démarrer, donc aucun workflow ne pouvait
 * compenser.
 */
final class WorkflowCancellationTest extends TestCase
{
    private InMemoryEventStore $eventStore;
    private InMemoryActivityTransport $transport;
    private RegistryActivityExecutor $executor;
    private ExecutionRuntime $runtime;
    private ExecutionEngine $engine;

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStore();
        $this->transport = new InMemoryActivityTransport();
        $this->executor = new RegistryActivityExecutor();
        $this->runtime = new ExecutionRuntime($this->eventStore, $this->transport, $this->executor, 0, null, true);
        $this->engine = new ExecutionEngine($this->eventStore, $this->runtime);
    }

    public function testCancellationIsRaisedAtTheAwaitPoint(): void
    {
        $this->executor->register('slow', static fn(): string => 'never used');
        $handler = static fn(WorkflowEnvironment $env): mixed => $env->await($env->activity('slow', []));

        $this->startAndSuspend('exec-1', $handler);
        $this->requestCancellation('exec-1');

        try {
            $this->engine->resume('exec-1', $handler);
            self::fail('la reprise doit se terminer sur une annulation');
        } catch (WorkflowCancelledException $e) {
            self::assertSame(ActivityCancellationReason::WORKFLOW_CANCELLED, $e->reason);
        }

        self::assertNotNull($this->firstOf('exec-1', WorkflowExecutionCancelled::class));
        self::assertFalse(ParentChildWorkflowCoordinator::isChildRunActive($this->eventStore, 'exec-1'));

        // L'activité en attente est retirée avec la raison qui sert de trace de livraison.
        $cancelled = $this->firstOf('exec-1', ActivityCancelled::class);
        self::assertNotNull($cancelled);
        self::assertSame(ActivityCancellationReason::WORKFLOW_CANCELLED, $cancelled->reason());
    }

    public function testWorkflowCanCompensateBeforeTheCancellationTakesEffect(): void
    {
        $refunds = 0;
        $this->executor->register('charge', static fn(): string => 'charged');
        $this->executor->register('refund', static function () use (&$refunds): string {
            ++$refunds;

            return 'refunded';
        });

        $handler = static function (WorkflowEnvironment $env): mixed {
            try {
                return $env->await($env->activity('charge', []));
            } catch (WorkflowCancelledFailure $e) {
                $env->await($env->activity('refund', []));

                throw $e;
            }
        };

        $this->startAndSuspend('exec-2', $handler);
        $this->requestCancellation('exec-2');

        // Tâche 1 : l'annulation est relevée dans le fiber, la compensation est planifiée.
        $this->resumeExpectingSuspension('exec-2', $handler);
        $this->drainActivities('exec-2');

        // Tâche 2 : rejeu — la compensation est réglée par le journal, puis l'annulation ressort.
        try {
            $this->engine->resume('exec-2', $handler);
            self::fail('la compensation terminée, l’annulation doit ressortir');
        } catch (WorkflowCancelledException) {
        }

        self::assertSame(1, $refunds, 'la compensation doit s’exécuter exactement une fois');
        self::assertNotNull($this->firstOf('exec-2', WorkflowExecutionCancelled::class));
    }

    public function testAWorkflowMaySwallowTheCancellationAndComplete(): void
    {
        $this->executor->register('slow', static fn(): string => 'never used');
        $handler = static function (WorkflowEnvironment $env): string {
            try {
                return $env->await($env->activity('slow', []));
            } catch (WorkflowCancelledFailure) {
                return 'finished anyway';
            }
        };

        $this->startAndSuspend('exec-3', $handler);
        $this->requestCancellation('exec-3');

        self::assertSame('finished anyway', $this->engine->resume('exec-3', $handler));
        self::assertNotNull($this->firstOf('exec-3', ExecutionCompleted::class));
        self::assertNull($this->firstOf('exec-3', WorkflowExecutionCancelled::class));
    }

    public function testAWorkflowThatNeverAwaitsCompletesWithoutObservingTheCancellation(): void
    {
        $this->eventStore->append(new ExecutionStarted('exec-4', []));
        $this->requestCancellation('exec-4');

        self::assertSame('done', $this->engine->resume('exec-4', static fn(WorkflowEnvironment $env): string => 'done'));
        self::assertNotNull($this->firstOf('exec-4', ExecutionCompleted::class));
    }

    public function testRaceLosersKeepTheirOwnCancellationSemantics(): void
    {
        // Garde : une activité perdante d'un any() ne doit pas être confondue avec une
        // annulation de workflow — même événement, raison différente.
        $this->executor->register('fast', static fn(): string => 'winner');
        $runner = new \Gplanchat\Durable\InMemoryWorkflowRunner($this->eventStore, $this->transport, $this->executor);

        $result = $runner->run('race-1', static fn(WorkflowEnvironment $env): mixed => $env->await($env->any(
            $env->activity('fast', []),
            $env->timer(3600.0),
        )));

        self::assertSame('winner', $result);
        $timerCancelled = $this->firstOf('race-1', \Gplanchat\Durable\Event\TimerCancelled::class);
        self::assertNotNull($timerCancelled);
        self::assertSame(ActivityCancellationReason::RACE_SUPERSEDED, $timerCancelled->reason());
        self::assertNull($this->firstOf('race-1', WorkflowExecutionCancelled::class));
    }

    // -------------------------------------------------------------------------

    private function startAndSuspend(string $executionId, callable $handler): void
    {
        try {
            $this->engine->start($executionId, $handler);
            self::fail('le workflow devait suspendre sur son activité');
        } catch (WorkflowSuspendedException) {
        }
    }

    private function resumeExpectingSuspension(string $executionId, callable $handler): void
    {
        try {
            $this->engine->resume($executionId, $handler);
            self::fail('le workflow devait suspendre sur sa compensation');
        } catch (WorkflowSuspendedException) {
        }
    }

    private function requestCancellation(string $executionId): void
    {
        $this->eventStore->append(new WorkflowCancellationRequested($executionId, 'operator', null));
    }

    private function drainActivities(string $executionId): void
    {
        $this->runtime->runUntilIdle(new ExecutionContext(
            $executionId,
            new EventStoreHistorySource($this->eventStore, $executionId),
            new EventStoreCommandBuffer($this->eventStore, $this->transport, $executionId),
        ));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function firstOf(string $executionId, string $class): ?object
    {
        foreach ($this->eventStore->readStream($executionId) as $event) {
            if ($event instanceof $class) {
                return $event;
            }
        }

        return null;
    }
}
