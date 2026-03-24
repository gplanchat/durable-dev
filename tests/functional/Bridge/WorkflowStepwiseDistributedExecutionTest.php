<?php

declare(strict_types=1);

namespace functional\Gplanchat\Durable\Bridge;

use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Tests\Support\DistributedWorkflowExpectedJournal;
use Gplanchat\Durable\Tests\Support\DurableTestCase;
use Gplanchat\Durable\Tests\Support\StepwiseWorkflowHarness;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Scénarios « en cours d'exécution » : file d'activités, reprise après complétion,
 * terminaison après la dernière activité (runtime distribué simulé).
 *
 * @internal
 */
#[CoversClass(InMemoryWorkflowRunner::class)]
#[CoversClass(ExecutionEngine::class)]
final class WorkflowStepwiseDistributedExecutionTest extends DurableTestCase
{
    #[Test]
    #[TestDox('Une activité greet : file puis journal intermédiaires, puis complétion')]
    public function singleGreetActivityStepwiseQueueResumeAndCompletion(): void
    {
        $executionId = ExecutionId::fromString('01900000-0000-7000-8000-000000000010');
        $executionKey = $executionId->toString();

        $eventStore = new InMemoryEventStore();
        $activityTransport = new InMemoryActivityTransport();
        $activityExecutor = new RegistryActivityExecutor();
        $activityExecutor->register('greet', fn (array $p) => \sprintf('Hello, %s!', $p['name'] ?? 'World'));

        $harness = StepwiseWorkflowHarness::create($eventStore, $activityTransport, $activityExecutor);

        $workflow = static function (WorkflowEnvironment $env): mixed {
            return $env->await($env->activity('greet', ['name' => 'Durable']));
        };

        self::assertTrue(
            $harness->start($executionKey, $workflow),
            'après le premier await, le workflow doit être suspendu',
        );
        $this->assertActivityTransportPendingEquals($activityTransport, [
            ['name' => 'greet', 'payload' => ['name' => 'Durable']],
        ]);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::singleGreetActivityAfterFirstScheduled($executionId),
            $executionId,
        );

        self::assertTrue(
            $harness->drainOneQueuedActivity($executionKey),
            'une activité doit être consommée depuis la file',
        );
        $this->assertActivityTransportPendingEquals($activityTransport, []);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::singleGreetActivityAfterFirstCompleted($executionId, 'Hello, Durable!'),
            $executionId,
        );

        self::assertFalse(
            $harness->resume($executionKey, $workflow),
            'après complétion de la seule activité, le workflow doit se terminer',
        );
        self::assertSame('Hello, Durable!', $harness->lastCompletedResult());
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::singleGreetActivity($executionId, 'Hello, Durable!'),
            $executionId,
        );
    }

    #[Test]
    #[TestDox('Trois doubles en chaîne : à chaque suspend une activité en file, reprise jusqu’à ExecutionCompleted')]
    public function threeSequentialDoublesStepwiseQueuesAndResumesUntilCompletion(): void
    {
        $executionId = ExecutionId::fromString('01900000-0000-7000-8000-000000000011');
        $executionKey = $executionId->toString();

        $eventStore = new InMemoryEventStore();
        $activityTransport = new InMemoryActivityTransport();
        $activityExecutor = new RegistryActivityExecutor();
        $activityExecutor->register('double', fn (array $p) => ($p['x'] ?? 0) * 2);

        $harness = StepwiseWorkflowHarness::create($eventStore, $activityTransport, $activityExecutor);

        $workflow = static function (WorkflowEnvironment $env): mixed {
            $a = $env->await($env->activity('double', ['x' => 5]));
            $b = $env->await($env->activity('double', ['x' => $a]));

            return $env->await($env->activity('double', ['x' => $b]));
        };

        // --- 1re activité : mise en file ---
        self::assertTrue($harness->start($executionKey, $workflow));
        $this->assertActivityTransportPendingEquals($activityTransport, [
            ['name' => 'double', 'payload' => ['x' => 5]],
        ]);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::threeSequentialDoublesAfterFirstScheduled($executionId),
            $executionId,
        );

        self::assertTrue($harness->drainOneQueuedActivity($executionKey));
        $this->assertActivityTransportPendingEquals($activityTransport, []);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::threeSequentialDoublesAfterFirstCompleted($executionId),
            $executionId,
        );

        // --- 2e activité ---
        self::assertTrue($harness->resume($executionKey, $workflow));
        $this->assertActivityTransportPendingEquals($activityTransport, [
            ['name' => 'double', 'payload' => ['x' => 10]],
        ]);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::threeSequentialDoublesAfterSecondScheduled($executionId),
            $executionId,
        );

        self::assertTrue($harness->drainOneQueuedActivity($executionKey));
        $this->assertActivityTransportPendingEquals($activityTransport, []);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::threeSequentialDoublesAfterSecondCompleted($executionId),
            $executionId,
        );

        // --- 3e activité ---
        self::assertTrue($harness->resume($executionKey, $workflow));
        $this->assertActivityTransportPendingEquals($activityTransport, [
            ['name' => 'double', 'payload' => ['x' => 20]],
        ]);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::threeSequentialDoublesAfterThirdScheduled($executionId),
            $executionId,
        );

        self::assertTrue($harness->drainOneQueuedActivity($executionKey));
        $this->assertActivityTransportPendingEquals($activityTransport, []);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::threeSequentialDoublesAfterThirdCompleted($executionId),
            $executionId,
        );

        // --- Dernière reprise : plus d’activité, résultat final ---
        self::assertFalse($harness->resume($executionKey, $workflow));
        self::assertSame(40, $harness->lastCompletedResult());
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::threeSequentialDoubles($executionId),
            $executionId,
        );
    }

    #[Test]
    #[TestDox('all() sur trois id : trois activités en file dès le premier suspend, puis complétions FIFO jusqu’au résultat agrégé')]
    public function threeParallelIdActivitiesViaAllStepwiseMultiQueuedThenDrains(): void
    {
        $executionId = ExecutionId::fromString('01900000-0000-7000-8000-000000000012');
        $executionKey = $executionId->toString();

        $eventStore = new InMemoryEventStore();
        $activityTransport = new InMemoryActivityTransport();
        $activityExecutor = new RegistryActivityExecutor();
        $activityExecutor->register('id', fn (array $p) => $p['id'] ?? 0);

        $harness = StepwiseWorkflowHarness::create($eventStore, $activityTransport, $activityExecutor);

        $workflow = static function (WorkflowEnvironment $env): mixed {
            $a1 = $env->activity('id', ['id' => 'A']);
            $a2 = $env->activity('id', ['id' => 'B']);
            $a3 = $env->activity('id', ['id' => 'C']);

            return $env->all($a1, $a2, $a3);
        };

        self::assertTrue($harness->start($executionKey, $workflow));
        $this->assertActivityTransportPendingEquals($activityTransport, [
            ['name' => 'id', 'payload' => ['id' => 'A']],
            ['name' => 'id', 'payload' => ['id' => 'B']],
            ['name' => 'id', 'payload' => ['id' => 'C']],
        ]);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::threeParallelIdActivitiesAfterAllScheduled($executionId),
            $executionId,
        );

        self::assertTrue($harness->drainOneQueuedActivity($executionKey));
        $this->assertActivityTransportPendingEquals($activityTransport, [
            ['name' => 'id', 'payload' => ['id' => 'B']],
            ['name' => 'id', 'payload' => ['id' => 'C']],
        ]);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::threeParallelIdActivitiesAfterFirstDrain($executionId),
            $executionId,
        );

        self::assertTrue($harness->resume($executionKey, $workflow));
        $this->assertActivityTransportPendingEquals($activityTransport, [
            ['name' => 'id', 'payload' => ['id' => 'B']],
            ['name' => 'id', 'payload' => ['id' => 'C']],
        ]);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::threeParallelIdActivitiesAfterFirstDrain($executionId),
            $executionId,
            'reprise sur le 2e await : pas de nouvel événement tant que B n’est pas drainée',
        );

        self::assertTrue($harness->drainOneQueuedActivity($executionKey));
        $this->assertActivityTransportPendingEquals($activityTransport, [
            ['name' => 'id', 'payload' => ['id' => 'C']],
        ]);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::threeParallelIdActivitiesAfterSecondDrain($executionId),
            $executionId,
        );

        self::assertTrue($harness->resume($executionKey, $workflow));
        $this->assertActivityTransportPendingEquals($activityTransport, [
            ['name' => 'id', 'payload' => ['id' => 'C']],
        ]);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::threeParallelIdActivitiesAfterSecondDrain($executionId),
            $executionId,
            'suspend sur le 3e await : journal inchangé après reprise',
        );

        self::assertTrue($harness->drainOneQueuedActivity($executionKey));
        $this->assertActivityTransportPendingEquals($activityTransport, []);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::threeParallelIdActivitiesAfterThirdDrain($executionId),
            $executionId,
        );

        self::assertFalse($harness->resume($executionKey, $workflow));
        self::assertSame(['A', 'B', 'C'], $harness->lastCompletedResult());
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::threeParallelIdActivities($executionId),
            $executionId,
        );
    }

    #[Test]
    #[TestDox('any() : deux activités en file, un drain puis reprise — le gagnant suffit, la seconde peut rester en file')]
    public function competitiveAnyStepwiseTwoQueuedThenFirstDrainCompletesWorkflow(): void
    {
        $executionId = ExecutionId::fromString('01900000-0000-7000-8000-000000000013');
        $executionKey = $executionId->toString();

        $eventStore = new InMemoryEventStore();
        $activityTransport = new InMemoryActivityTransport();
        $activityExecutor = new RegistryActivityExecutor();
        $activityExecutor->register('first', fn () => 'first');
        $activityExecutor->register('second', fn () => 'second');

        $harness = StepwiseWorkflowHarness::create($eventStore, $activityTransport, $activityExecutor);

        $workflow = static function (WorkflowEnvironment $env): mixed {
            $a1 = $env->activity('first', []);
            $a2 = $env->activity('second', []);

            return $env->any($a1, $a2);
        };

        self::assertTrue($harness->start($executionKey, $workflow));
        $this->assertActivityTransportPendingEquals($activityTransport, [
            ['name' => 'first', 'payload' => []],
            ['name' => 'second', 'payload' => []],
        ]);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::competitiveAnyAfterBothScheduled($executionId),
            $executionId,
        );

        self::assertTrue($harness->drainOneQueuedActivity($executionKey));
        $this->assertActivityTransportPendingEquals($activityTransport, [
            ['name' => 'second', 'payload' => []],
        ]);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::competitiveAnyAfterFirstActivityDrained($executionId),
            $executionId,
        );

        self::assertFalse($harness->resume($executionKey, $workflow));
        self::assertSame('first', $harness->lastCompletedResult());
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            DistributedWorkflowExpectedJournal::competitiveAnyCompletedAfterFirstWinsWithoutSecondActivityRun(
                $executionId,
            ),
            $executionId,
        );
        $this->assertActivityTransportPendingEquals(
            $activityTransport,
            [],
            'la seconde activité perdante est retirée de la file (any/race)',
        );
    }
}
