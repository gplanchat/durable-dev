<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Exception\WorkflowCancelledException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\ParentChildWorkflowCoordinator;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowCancellationRequested n'avait aucune contrepartie terminale : un enfant en
 * ParentClosePolicy::RequestCancel restait « actif » pour toujours et sa reprise était
 * redélivrée sans fin.
 */
final class WorkflowCancellationTest extends TestCase
{
    private InMemoryEventStore $eventStore;
    private ExecutionEngine $engine;

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStore();
        $this->engine = new ExecutionEngine(
            $this->eventStore,
            new ExecutionRuntime(
                $this->eventStore,
                new InMemoryActivityTransport(),
                new RegistryActivityExecutor(),
                0,
                null,
                true,
            ),
        );
    }

    public function testPendingCancellationStopsTheRunAndClosesTheJournal(): void
    {
        $this->eventStore->append(new ExecutionStarted('child-1', []));
        $this->eventStore->append(new WorkflowCancellationRequested('child-1', 'parent_request_cancel', 'parent-1'));

        self::assertTrue(ParentChildWorkflowCoordinator::isChildRunActive($this->eventStore, 'child-1'));

        try {
            $this->engine->resume('child-1', static fn (WorkflowEnvironment $env): string => 'must not run');
            self::fail('resume() doit propager WorkflowCancelledException');
        } catch (WorkflowCancelledException $e) {
            self::assertSame('parent_request_cancel', $e->reason);
        }

        $cancelled = null;
        foreach ($this->eventStore->readStream('child-1') as $event) {
            if ($event instanceof WorkflowExecutionCancelled) {
                $cancelled = $event;
            }
        }
        self::assertNotNull($cancelled);
        self::assertSame('parent-1', $cancelled->sourceParentExecutionId());
        self::assertFalse(ParentChildWorkflowCoordinator::isChildRunActive($this->eventStore, 'child-1'));
    }

    public function testACancellationAlreadyHonoredDoesNotFireTwice(): void
    {
        $this->eventStore->append(new ExecutionStarted('child-2', []));
        $this->eventStore->append(new WorkflowCancellationRequested('child-2', 'parent_request_cancel'));
        $this->eventStore->append(new WorkflowExecutionCancelled('child-2', 'parent_request_cancel'));

        // Le journal est clos : la reprise repart normalement (et se termine) au lieu de
        // ré-annuler en boucle.
        $result = $this->engine->resume('child-2', static fn (WorkflowEnvironment $env): string => 'done');
        self::assertSame('done', $result);
    }
}
