<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use PHPUnit\Framework\TestCase;

/**
 * Sonde, et non fonctionnalité : le change « backend-neutral-workflow-dashboard » choisit une table
 * de projection plutôt qu'une colonne ajoutée aux métadonnées, et ce choix repose entièrement sur la
 * propriété gardée ici — `ensure()` crée les tables manquantes et ne touche pas aux existantes.
 *
 * Le paquet ne livre pas de migrations. Si cette propriété tombe, une installation qui existe déjà
 * n'obtiendrait jamais la nouvelle table, et le lecteur du tableau de bord interrogerait une table
 * absente. La sonde est donc épinglée pour que personne ne la casse en croyant `DurableSchema`
 * anodin.
 *
 * @see DUR030
 * @see openspec/changes/backend-neutral-workflow-dashboard/design.md
 */
final class DurableSchemaIncrementalCreationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testMissingTablesAreCreatedBesideExistingOnes(): void
    {
        // Une installation partielle : le journal existe déjà, le reste non.
        $this->createEventsTableAlone();
        self::assertSame(['durable_events'], $this->durableTables());

        (new DurableSchema($this->connection))->ensure();

        self::assertSame(
            [
                'durable_child_workflow_parent_link',
                'durable_events',
                'durable_workflow_metadata',
                'durable_workflow_runs',
            ],
            $this->durableTables(),
        );
    }

    /**
     * L'assertion qui a des dents : si `ensure()` recréait la table au lieu de la laisser, les
     * lignes disparaîtraient sans que le décompte des tables bouge d'un pouce.
     */
    public function testAnExistingTableKeepsItsRows(): void
    {
        $this->createEventsTableAlone();
        $this->connection->insert('durable_events', [
            'execution_id' => 'exec-1',
            'event_type' => 'ExecutionStarted',
            'payload' => '{}',
            'recorded_at' => '2026-08-26 12:00:00',
        ]);

        (new DurableSchema($this->connection))->ensure();

        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM durable_events'),
        );
    }

    /**
     * La propriété dont dépend la projection : une table *nouvelle*, inconnue de l'installation,
     * apparaît sans que les autres soient retouchées. Le nom est délibérément étranger au schéma —
     * y mettre le nom d'une table réelle a fait entrer ce test en collision avec la projection le
     * jour où elle a été déclarée.
     */
    public function testATableTheInstallHasNeverSeenIsCreated(): void
    {
        (new DurableSchema($this->connection))->ensure();
        $before = $this->durableTables();

        $schema = new Schema();
        $projection = $schema->createTable('durable_probe_unknown');
        $projection->addColumn('execution_id', 'string', ['length' => 128]);
        $projection->setPrimaryKey(['execution_id']);
        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }

        $expected = [...$before, 'durable_probe_unknown'];
        sort($expected);

        self::assertSame($expected, $this->durableTables());
    }

    private function createEventsTableAlone(): void
    {
        $schema = new Schema();
        (new DurableSchema($this->connection))->addToSchema(
            $schema,
            ['durable_workflow_metadata', 'durable_child_workflow_parent_link', 'durable_workflow_runs'],
        );
        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    /**
     * @return list<string>
     */
    private function durableTables(): array
    {
        $names = array_values(array_filter(
            $this->connection->createSchemaManager()->listTableNames(),
            static fn(string $name): bool => str_starts_with($name, 'durable_'),
        ));
        sort($names);

        return $names;
    }
}
