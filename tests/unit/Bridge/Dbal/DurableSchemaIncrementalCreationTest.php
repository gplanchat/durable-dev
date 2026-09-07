<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use PHPUnit\Framework\TestCase;

/**
 * A probe, and not a feature: the "backend-neutral-workflow-dashboard" change picks a projection
 * table rather than a column added to the metadata, and that choice rests entirely on the property
 * guarded here — `ensure()` creates the missing tables and does not touch the existing ones.
 *
 * The package ships no migrations. If this property falls, an installation that already exists
 * would never get the new table, and the dashboard reader would query a table that is not there.
 * The probe is therefore pinned, so that nobody breaks it believing `DurableSchema` to be
 * harmless.
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
        // A partial installation: the journal already exists, the rest does not.
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
     * The assertion with teeth: if `ensure()` re-created the table instead of leaving it be, the
     * rows would vanish without the table count moving an inch.
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
     * The property the projection depends on: a *new* table, unknown to the installation, appears
     * without the others being touched up. The name is deliberately foreign to the schema —
     * putting a real table name there made this test collide with the projection on the day the
     * projection was declared.
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
