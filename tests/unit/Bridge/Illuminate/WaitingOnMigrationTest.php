<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * An application that already ran the create migration gets `waiting_on` from `php artisan migrate`
 * (#324), and a rollback takes it away again.
 */
final class WaitingOnMigrationTest extends TestCase
{
    public function testAnExistingTableGainsTheColumnAndARollbackRemovesIt(): void
    {
        $connection = self::connection();
        $connection->statement('CREATE TABLE durable_workflow_runs (execution_id VARCHAR(128) NOT NULL PRIMARY KEY, status VARCHAR(32) NOT NULL, started_at DATETIME NOT NULL)');
        $migration = self::migration($connection);

        $migration->up();
        $migration->up();
        self::assertTrue($connection->getSchemaBuilder()->hasColumn('durable_workflow_runs', 'waiting_on'), 'added once, and twice is harmless');

        $migration->down();
        self::assertFalse($connection->getSchemaBuilder()->hasColumn('durable_workflow_runs', 'waiting_on'));
    }

    private static function connection(): Connection
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
        Facade::setFacadeApplication($container);

        return require __DIR__ . '/../../../../src/Bridge/Illuminate/Migrations/2026_09_24_000001_add_waiting_on_to_durable_workflow_runs.php';
    }
}
