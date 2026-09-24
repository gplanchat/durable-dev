<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * An application that already ran the create migration gets `picked_up_at` from `php artisan
 * migrate` (#447). The rows already there are taken as picked up: better no signal than a false
 * "waiting for a worker" on every run that predates the column.
 */
final class PickedUpAtMigrationTest extends TestCase
{
    public function testAnExistingTableGainsTheColumnAndItsRowsCountAsPickedUp(): void
    {
        $connection = self::connection();
        $connection->statement('CREATE TABLE durable_workflow_runs (execution_id VARCHAR(128) NOT NULL PRIMARY KEY, workflow_type VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, started_at DATETIME NOT NULL, ended_at DATETIME DEFAULT NULL)');
        $connection->table('durable_workflow_runs')->insert(['execution_id' => 'old', 'workflow_type' => 'App\\OrderWorkflow', 'status' => 'running', 'started_at' => '2026-09-01 10:00:00']);

        self::migration($connection)->up();

        self::assertTrue($connection->getSchemaBuilder()->hasColumn('durable_workflow_runs', 'picked_up_at'));
        self::assertSame('2026-09-01 10:00:00', $connection->table('durable_workflow_runs')->value('picked_up_at'));
    }

    public function testATableThatHasTheColumnIsLeftAlone(): void
    {
        $connection = self::connection();
        $connection->statement('CREATE TABLE durable_workflow_runs (execution_id VARCHAR(128) NOT NULL PRIMARY KEY, status VARCHAR(32) NOT NULL, started_at DATETIME NOT NULL, picked_up_at DATETIME DEFAULT NULL)');
        $connection->table('durable_workflow_runs')->insert(['execution_id' => 'new', 'status' => 'running', 'started_at' => '2026-09-24 10:00:00']);

        self::migration($connection)->up();

        self::assertNull($connection->table('durable_workflow_runs')->value('picked_up_at'), 'a fresh run keeps waiting');
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

        return require __DIR__ . '/../../../../src/Bridge/Illuminate/Migrations/2026_09_24_000000_add_picked_up_at_to_durable_workflow_runs.php';
    }
}
