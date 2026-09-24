<?php

declare(strict_types=1);

namespace unit\Bridge;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue filters on `status` and orders by `started_at`; the only index was on `started_at`
 * (#339). Both SQL bridges declare `(status, started_at)`, and a migration adds it to a Laravel
 * table created before.
 */
final class RunsAreListedByStatusThroughAnIndexTest extends TestCase
{
    public function testTheDbalSchemaDeclaresTheCompositeIndex(): void
    {
        $schema = new Schema();
        (new DurableSchema(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])))->addToSchema($schema);

        $columns = array_map(
            static fn($index): array => $index->getColumns(),
            array_values($schema->getTable('durable_workflow_runs')->getIndexes()),
        );

        self::assertContains(['status', 'started_at'], $columns);
    }

    public function testTheMigrationAddsTheIndexToAnExistingTable(): void
    {
        $connection = self::illuminate();
        $connection->statement('CREATE TABLE durable_workflow_runs (execution_id VARCHAR(128) NOT NULL PRIMARY KEY, status VARCHAR(32) NOT NULL, started_at DATETIME NOT NULL)');

        self::migration($connection)->up();
        self::migration($connection)->up();

        self::assertTrue($connection->getSchemaBuilder()->hasIndex('durable_workflow_runs', ['status', 'started_at']));
    }

    private static function illuminate(): Connection
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        return $capsule->getConnection();
    }

    private static function migration(Connection $connection): object
    {
        $container = new Container();
        $container->instance('db.schema', $connection->getSchemaBuilder());
        Facade::clearResolvedInstances();
        // A bare container is all the Schema facade needs outside a Laravel application.
        Facade::setFacadeApplication($container); // @phpstan-ignore argument.type (a bare Container, not an Application: deliberate)

        return require __DIR__ . '/../../../src/Bridge/Illuminate/Migrations/2026_09_25_000000_add_status_index_to_durable_workflow_runs.php';
    }
}
