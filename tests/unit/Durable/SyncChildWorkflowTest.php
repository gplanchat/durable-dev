<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\ChildWorkflowRunner;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\ParentChildWorkflowCoordinator;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Un enfant exécuté **inline** (ChildWorkflowRunner sans démarrage différé Messenger)
 * journalisait son issue avec completeWorkflow()/failWorkflow() sur le buffer du PARENT :
 * le journal du parent était clôturé avec le résultat de l'enfant, et le
 * ChildWorkflowCompleted que le replay recherche n'existait jamais.
 */
final class SyncChildWorkflowTest extends TestCase
{
    private InMemoryEventStore $eventStore;
    private WorkflowRegistry $registry;
    private ExecutionEngine $engine;

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $executor = new RegistryActivityExecutor();
        $this->registry = new WorkflowRegistry();
        $runtime = new ExecutionRuntime($this->eventStore, $transport, $executor, 0, null, true);

        $this->engine = new ExecutionEngine(
            $this->eventStore,
            $runtime,
            new ChildWorkflowRunner($this->eventStore, $runtime, $this->registry, $executor, 0, false),
        );
    }

    public function testChildOutcomeLandsOnTheParentAsAChildEvent(): void
    {
        $this->registry->registerFactory('Child', static fn (array $i) => static fn (WorkflowEnvironment $env): string => 'child-result');

        $result = $this->engine->start('parent-1', static fn (WorkflowEnvironment $env): string
            => 'parent-saw:'.$env->executeChildWorkflow('Child', []));

        self::assertSame('parent-saw:child-result', $result);

        $types = $this->shortNames('parent-1');
        self::assertSame(
            ['ExecutionStarted', 'ChildWorkflowScheduled', 'ChildWorkflowCompleted', 'ExecutionCompleted'],
            $types,
        );

        // Un seul ExecutionCompleted, et il porte le résultat du PARENT.
        $completed = array_values(array_filter(
            iterator_to_array($this->eventStore->readStream('parent-1'), false),
            static fn (object $e): bool => $e instanceof ExecutionCompleted,
        ));
        self::assertCount(1, $completed);
        self::assertSame('parent-saw:child-result', $completed[0]->result());
    }

    public function testChildIsNotReExecutedWhenTheParentReplays(): void
    {
        $childRuns = 0;
        $this->registry->registerFactory('Child', static function (array $i) use (&$childRuns) {
            return static function (WorkflowEnvironment $env) use (&$childRuns): string {
                ++$childRuns;

                return 'child-result';
            };
        });

        $handler = static fn (WorkflowEnvironment $env): string
            => 'parent-saw:'.$env->executeChildWorkflow('Child', []);

        $this->engine->start('parent-2', $handler);
        self::assertSame(1, $childRuns);

        // Le replay doit relire ChildWorkflowCompleted, pas relancer l'enfant.
        $this->engine->resume('parent-2', $handler);
        self::assertSame(1, $childRuns, 'l’enfant ne doit pas être réexécuté au replay du parent');
    }

    public function testFailingChildLandsAsChildWorkflowFailedNotAsAParentFailure(): void
    {
        $this->registry->registerFactory('Child', static fn (array $i) => static function (WorkflowEnvironment $env): never {
            throw new \DomainException('child exploded');
        });

        try {
            $this->engine->start('parent-3', static fn (WorkflowEnvironment $env): mixed
                => $env->executeChildWorkflow('Child', []));
        } catch (\Throwable) {
            // Le parent ne gère pas l'échec de l'enfant : c'est attendu ici.
        }

        $types = $this->shortNames('parent-3');
        self::assertContains('ChildWorkflowFailed', $types);
        self::assertNotContains('ExecutionCompleted', $types);

        $failed = array_values(array_filter(
            iterator_to_array($this->eventStore->readStream('parent-3'), false),
            static fn (object $e): bool => $e instanceof ChildWorkflowFailed,
        ));
        self::assertStringContainsString('child exploded', $failed[0]->failureMessage());
    }

    public function testParentStaysActiveWhileTheChildCompletes(): void
    {
        $this->registry->registerFactory('Child', static fn (array $i) => static function (WorkflowEnvironment $env): string {
            return 'child-result';
        });

        $seenActive = null;
        $this->engine->start('parent-4', function (WorkflowEnvironment $env) use (&$seenActive): string {
            $child = $env->executeChildWorkflow('Child', []);
            $seenActive = ParentChildWorkflowCoordinator::isChildRunActive($this->eventStore, 'parent-4');

            return $child;
        });

        self::assertTrue($seenActive, 'le parent ne doit pas être vu comme terminé pendant son propre run');
        self::assertNotEmpty(array_filter(
            iterator_to_array($this->eventStore->readStream('parent-4'), false),
            static fn (object $e): bool => $e instanceof ChildWorkflowCompleted,
        ));
    }

    /** @return list<string> */
    private function shortNames(string $executionId): array
    {
        $out = [];
        foreach ($this->eventStore->readStream($executionId) as $event) {
            $out[] = (new \ReflectionClass($event))->getShortName();
        }

        return $out;
    }
}
