<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\SuiteActivities;

/**
 * DUR030 promet le **même modèle d'écriture** sur le backend DBAL que sur les deux autres : la
 * réduction est opérationnelle, jamais dans le modèle de programmation. Ce test exécute un vrai
 * workflow — activité, minuteur, effet de bord — contre les deux journaux et compare ce que le
 * replay lit de chacun. Un aller-retour SQL qui déforme un payload casserait le replay en silence,
 * pas au moment de l'écriture.
 *
 * @see DUR030
 */
final class DbalBackendParityTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testAWorkflowRunsIdenticallyOnBothJournals(): void
    {
        $inMemory = new InMemoryEventStore();
        $dbal = new DbalEventStore($this->connection, new DurableSchema($this->connection));

        $inMemoryResult = $this->runWorkflow($inMemory, 'exec-mem');
        $dbalResult = $this->runWorkflow($dbal, 'exec-sql');

        self::assertSame($inMemoryResult, $dbalResult, 'le workflow doit rendre le même résultat');
        self::assertSame(
            $this->journalShape($inMemory, 'exec-mem'),
            $this->journalShape($dbal, 'exec-sql'),
            'les deux journaux doivent enregistrer les mêmes événements, dans le même ordre',
        );
    }

    /**
     * `EventStoreHistorySource` relit le flux à chaque interrogation de slot. Le store in-memory
     * rend un tableau, le store DBAL un générateur adossé à une requête : ce test garde le fait
     * qu'un flux consommable une seule fois suffit quand même.
     */
    public function testReplaySlotLookupsAgreeAcrossBothStores(): void
    {
        $inMemory = new InMemoryEventStore();
        $dbal = new DbalEventStore($this->connection, new DurableSchema($this->connection));

        $this->runWorkflow($inMemory, 'exec-mem');
        $this->runWorkflow($dbal, 'exec-sql');

        $fromMemory = new EventStoreHistorySource($inMemory, 'exec-mem');
        $fromDbal = new EventStoreHistorySource($dbal, 'exec-sql');

        self::assertSame(
            $fromMemory->findActivitySlotResult(0)['result'],
            $fromDbal->findActivitySlotResult(0)['result'],
        );
        self::assertNull($fromDbal->findActivitySlotResult(1), 'une seule activité a été planifiée');

        // Les effets de bord portent un `mixed` : c'est là qu'un aller-retour JSON déforme.
        self::assertSame($fromMemory->findSideEffectForSlot(0), $fromDbal->findSideEffectForSlot(0));
        self::assertSame($fromMemory->findSideEffectForSlot(1), $fromDbal->findSideEffectForSlot(1));

        self::assertNotNull($fromDbal->findScheduledTimerId(0), 'le minuteur doit être relu depuis SQL');
    }

    /**
     * Activité, minuteur et deux effets de bord dont un payload non scalaire.
     */
    private function runWorkflow(EventStoreInterface $eventStore, string $executionId): mixed
    {
        $activityExecutor = new RegistryActivityExecutor();
        $activityExecutor->register('quote', static fn(array $payload): array => [
            'total' => 42.5,
            'currency' => 'EUR',
            'lines' => $payload['lines'] ?? [],
        ]);

        $runner = new InMemoryWorkflowRunner(
            $eventStore,
            new InMemoryActivityTransport(),
            $activityExecutor,
            0,
            new WorkflowRegistry(),
        );

        return $runner->run($executionId, static function (WorkflowEnvironment $wf): array {
            $nested = $wf->sideEffect(static fn(): array => ['nested' => ['deep' => true], 'ratio' => 0.1]);
            $quote = $wf->await($wf->activityStub(SuiteActivities::class)->quote(['a', 'b']));
            $wf->sleep(Duration::seconds(0.001));
            $flag = $wf->sideEffect(static fn(): string => 'after-timer');

            return ['nested' => $nested, 'quote' => $quote, 'flag' => $flag];
        });
    }

    /**
     * @return list<array{string, array<string, mixed>}> type d'événement + payload, dans l'ordre
     */
    private function journalShape(EventStoreInterface $store, string $executionId): array
    {
        return array_map(
            static fn(Event $event): array => [$event::class, self::scrub($event->payload())],
            iterator_to_array($store->readStream($executionId), false),
        );
    }

    /**
     * Deux exécutions distinctes tirent des identifiants et des horloges différents ; les
     * neutraliser laisse exactement ce que la comparaison doit porter : la forme du journal.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function scrub(array $payload): array
    {
        $volatile = [
            'timerId', 'activityId', 'sideEffectId', 'childExecutionId', 'executionId',
            'scheduledAt', 'queued_at', 'first_queued_at',
        ];

        foreach ($payload as $key => $value) {
            if (\in_array($key, $volatile, true)) {
                $payload[$key] = '<volatile>';
            } elseif (\is_array($value)) {
                $payload[$key] = self::scrub($value);
            }
        }

        return $payload;
    }
}
