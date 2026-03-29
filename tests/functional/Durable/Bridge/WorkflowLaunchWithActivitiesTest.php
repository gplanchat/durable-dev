<?php

declare(strict_types=1);

namespace functional\Gplanchat\Durable\Bridge;

use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use integration\Gplanchat\Durable\Support\DistributedWorkflowExpectedJournal;
use integration\Gplanchat\Durable\Support\DurableTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Scénarios de lancement de workflow avec runtime **toujours distribué** (InMemoryWorkflowRunner).
 * Chaque test possède son DataProvider : (ExecutionId, workflow, journal attendu).
 *
 * @internal
 */
#[CoversClass(InMemoryWorkflowRunner::class)]
#[CoversClass(ExecutionEngine::class)]
final class WorkflowLaunchWithActivitiesTest extends DurableTestCase
{
    #[Test]
    #[TestDox('Une activité greet unique produit le journal attendu et retourne la salutation')]
    #[DataProvider('singleGreetActivityScenarioProvider')]
    public function singleGreetActivityProducesExpectedJournal(
        ExecutionId $executionId,
        callable $workflow,
        InMemoryEventStore $expected,
    ): void {
        $eventStore = new InMemoryEventStore();
        $activityTransport = new InMemoryActivityTransport();
        $activityExecutor = new RegistryActivityExecutor();
        $workflowRunner = new InMemoryWorkflowRunner($eventStore, $activityTransport, $activityExecutor);

        $activityExecutor->register('greet', fn (array $p) => \sprintf('Hello, %s!', $p['name'] ?? 'World'));

        $result = $workflowRunner->run((string) $executionId, $workflow);

        self::assertSame('Hello, Durable!', $result);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            $expected,
            $executionId,
        );
    }

    /**
     * @return iterable<string, array{ExecutionId, callable, InMemoryEventStore}>
     */
    public static function singleGreetActivityScenarioProvider(): iterable
    {
        $executionId = ExecutionId::fromString('01900000-0000-7000-8000-000000000001');

        yield 'activité greet avec paramètre name' => [
            $executionId,
            static function (WorkflowEnvironment $env): mixed {
                return $env->await($env->activity('greet', ['name' => 'Durable']));
            },
            DistributedWorkflowExpectedJournal::singleGreetActivity($executionId, 'Hello, Durable!'),
        ];
    }

    #[Test]
    #[TestDox('Trois activités double en chaîne produisent le journal attendu et le résultat final 40')]
    #[DataProvider('threeSequentialDoublesScenarioProvider')]
    public function threeSequentialDoublesProduceExpectedJournal(
        ExecutionId $executionId,
        callable $workflow,
        InMemoryEventStore $expected,
    ): void {
        $eventStore = new InMemoryEventStore();
        $activityTransport = new InMemoryActivityTransport();
        $activityExecutor = new RegistryActivityExecutor();
        $workflowRunner = new InMemoryWorkflowRunner($eventStore, $activityTransport, $activityExecutor);

        $activityExecutor->register('double', fn (array $p) => ($p['x'] ?? 0) * 2);

        $result = $workflowRunner->run((string) $executionId, $workflow);

        self::assertSame(40, $result);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            $expected,
            $executionId,
        );
    }

    /**
     * @return iterable<string, array{ExecutionId, callable, InMemoryEventStore}>
     */
    public static function threeSequentialDoublesScenarioProvider(): iterable
    {
        $executionId = ExecutionId::fromString('01900000-0000-7000-8000-000000000002');

        yield 'enchaînement x → 2x sur 5, 10 puis 20' => [
            $executionId,
            static function (WorkflowEnvironment $env): mixed {
                $a = $env->await($env->activity('double', ['x' => 5]));
                $b = $env->await($env->activity('double', ['x' => $a]));

                return $env->await($env->activity('double', ['x' => $b]));
            },
            DistributedWorkflowExpectedJournal::threeSequentialDoubles($executionId),
        ];
    }

    #[Test]
    #[TestDox('Une activité identity persiste le payload ActivityScheduled et retourne la même structure')]
    #[DataProvider('identityActivityPayloadScenarioProvider')]
    public function identityActivityPersistsScheduledPayloadInJournal(
        ExecutionId $executionId,
        callable $workflow,
        InMemoryEventStore $expected,
    ): void {
        $eventStore = new InMemoryEventStore();
        $activityTransport = new InMemoryActivityTransport();
        $activityExecutor = new RegistryActivityExecutor();
        $workflowRunner = new InMemoryWorkflowRunner($eventStore, $activityTransport, $activityExecutor);

        $activityExecutor->register('identity', fn (array $p) => $p);

        $workflowRunner->run((string) $executionId, $workflow);

        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            $expected,
            $executionId,
        );
    }

    /**
     * @return iterable<string, array{ExecutionId, callable, InMemoryEventStore}>
     */
    public static function identityActivityPayloadScenarioProvider(): iterable
    {
        $executionId = ExecutionId::fromString('01900000-0000-7000-8000-000000000003');

        yield 'payload clé/valeur inchangé en sortie' => [
            $executionId,
            static function (WorkflowEnvironment $env): mixed {
                return $env->await($env->activity('identity', ['key' => 'value']));
            },
            DistributedWorkflowExpectedJournal::identityActivityScheduledPayload($executionId),
        ];
    }

    #[Test]
    #[TestDox('Trois activités id en parallèle (all) produisent le journal et le tableau A, B, C')]
    #[DataProvider('threeParallelIdActivitiesScenarioProvider')]
    public function threeParallelIdActivitiesProduceExpectedJournal(
        ExecutionId $executionId,
        callable $workflow,
        InMemoryEventStore $expected,
    ): void {
        $eventStore = new InMemoryEventStore();
        $activityTransport = new InMemoryActivityTransport();
        $activityExecutor = new RegistryActivityExecutor();
        $workflowRunner = new InMemoryWorkflowRunner($eventStore, $activityTransport, $activityExecutor);

        $activityExecutor->register('id', fn (array $p) => $p['id'] ?? 0);

        $result = $workflowRunner->run((string) $executionId, $workflow);

        self::assertSame(['A', 'B', 'C'], $result);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            $expected,
            $executionId,
        );
    }

    /**
     * @return iterable<string, array{ExecutionId, callable, InMemoryEventStore}>
     */
    public static function threeParallelIdActivitiesScenarioProvider(): iterable
    {
        $executionId = ExecutionId::fromString('01900000-0000-7000-8000-000000000004');

        yield 'all sur trois awaitables id' => [
            $executionId,
            static function (WorkflowEnvironment $env): mixed {
                $a1 = $env->activity('id', ['id' => 'A']);
                $a2 = $env->activity('id', ['id' => 'B']);
                $a3 = $env->activity('id', ['id' => 'C']);

                return $env->all($a1, $a2, $a3);
            },
            DistributedWorkflowExpectedJournal::threeParallelIdActivities($executionId),
        ];
    }

    #[Test]
    #[TestDox('Deux activités en course (any) : la première servie en FIFO gagne ; si le worker vide toute la file avant reprise, les deux complétions restent dans le journal')]
    #[DataProvider('competitiveActivitiesFifoScenarioProvider')]
    public function competitiveActivitiesFirstFifoWinsWithFullJournal(
        ExecutionId $executionId,
        callable $workflow,
        InMemoryEventStore $expected,
    ): void {
        $eventStore = new InMemoryEventStore();
        $activityTransport = new InMemoryActivityTransport();
        $activityExecutor = new RegistryActivityExecutor();
        $workflowRunner = new InMemoryWorkflowRunner($eventStore, $activityTransport, $activityExecutor);

        $activityExecutor->register('first', fn () => 'first');
        $activityExecutor->register('second', fn () => 'second');

        $result = $workflowRunner->run((string) $executionId, $workflow);

        self::assertSame('first', $result);
        $this->assertDistributedWorkflowJournalEquivalent(
            $eventStore,
            $expected,
            $executionId,
        );
    }

    /**
     * @return iterable<string, array{ExecutionId, callable, InMemoryEventStore}>
     */
    public static function competitiveActivitiesFifoScenarioProvider(): iterable
    {
        $executionId = ExecutionId::fromString('01900000-0000-7000-8000-000000000005');

        yield 'any avec file FIFO first puis second' => [
            $executionId,
            static function (WorkflowEnvironment $env): mixed {
                $a1 = $env->activity('first', []);
                $a2 = $env->activity('second', []);

                return $env->any($a1, $a2);
            },
            DistributedWorkflowExpectedJournal::competitiveFirstWinsFifo($executionId),
        ];
    }
}
