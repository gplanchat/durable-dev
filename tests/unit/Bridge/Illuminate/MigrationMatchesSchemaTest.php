<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * The two ways of creating the tables must create the same ones.
 *
 * The bridge has two: {@see DurableSchema} raises them on demand — what the tests and a worker
 * starting on an empty database live off — and the published migration raises them through `php
 * artisan migrate`, what an application will live off. Two paths to the same schema is two
 * chances to diverge, and the divergence would show up neither at the migration nor at the test:
 * it would show up in production, on a column that is missing or too short.
 *
 * This test raises both on two connections and compares column by column.
 */
final class MigrationMatchesSchemaTest extends TestCase
{
    private const TABLES = [
        'durable_events',
        'durable_workflow_metadata',
        'durable_workflow_runs',
        'durable_child_workflow_parent_link',
    ];

    /**
     * The comparison is made on the **DDL**, not on introspection.
     *
     * SQLite throws the lengths away: a column declared `varchar(32)` reads back there as
     * `varchar`, and `status` shortened to eight characters therefore went unnoticed — truncating
     * `continued_as_new`, eighteen characters, as soon as a real application runs on MySQL.
     * Laravel can render the DDL without executing it and without a server: the MySQL grammar,
     * for its part, carries the lengths **and** the indexes.
     */
    public function testTheMigrationAndTheOnDemandSchemaEmitTheSameDdl(): void
    {
        self::assertSame(
            self::ddl(static fn(Connection $connection) => (new DurableSchema($connection))->ensure()),
            self::ddl(static fn(Connection $connection) => self::migration($connection)->up()),
            'the migration and the on-demand schema do not raise the same tables',
        );
    }

    public function testTheMigrationCreatesEveryTableTheStoresWrite(): void
    {
        $migrated = self::byMigration();

        foreach (self::TABLES as $table) {
            self::assertTrue(
                $migrated->getSchemaBuilder()->hasTable($table),
                \sprintf('the migration forgets %s', $table),
            );
        }
    }

    public function testTheMigrationRollsBackWhatItCreated(): void
    {
        $connection = self::connection();
        $migration = self::migration($connection);
        $migration->up();
        $migration->down();

        foreach (self::TABLES as $table) {
            self::assertFalse(
                $connection->getSchemaBuilder()->hasTable($table),
                \sprintf('%s survives down()', $table),
            );
        }
    }

    private static function connection(): Connection
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        return $capsule->getConnection();
    }

    /**
     * The migration speaks through the `Schema` facade. Outside a Laravel application it must
     * therefore be given a container — that is all `Facade::setFacadeApplication()` asks for, and
     * it is also what makes this test possible without raising a whole application.
     */
    private static function migration(Connection $connection): object
    {
        $container = new Container();
        $container->instance('db.schema', $connection->getSchemaBuilder());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        return require __DIR__ . '/../../../../src/Bridge/Illuminate/Migrations/0001_01_01_000000_create_durable_tables.php';
    }

    private static function byMigration(): Connection
    {
        $connection = self::connection();
        self::migration($connection)->up();

        return $connection;
    }

    private static function byDurableSchema(): Connection
    {
        $connection = self::connection();
        (new DurableSchema($connection))->ensure();

        return $connection;
    }

    /**
     * The DDL rendered by both paths, on a MySQL connection that is never opened: `pretend()`
     * executes nothing and `select()` returns an empty array, so `hasTable()` concludes that
     * nothing exists and the four tables are emitted.
     *
     * @param callable(Connection): void $build
     *
     * @return list<string>
     */
    private static function ddl(callable $build): array
    {
        $capsule = new Manager();
        $capsule->addConnection([
            'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'unopened',
            'username' => 'none', 'password' => '', 'prefix' => '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        ], 'ddl');
        $connection = $capsule->getConnection('ddl');

        $queries = $connection->pretend(static function () use ($connection, $build): void {
            $build($connection);
        });

        // `pretend()` logs **every** query, reads included: the four `hasTable()` calls of
        // `DurableSchema` would arrive here as four more `select`s, and the comparison would bear
        // on the path taken rather than on the tables obtained. Only the DDL counts.
        return array_values(array_filter(
            array_map(static fn(array $query): string => $query['query'], $queries),
            static fn(string $query): bool => (bool) preg_match('/^(create|alter|drop)\s/i', $query),
        ));
    }

    /**
     * A column's name is not enough. `status` shortened from 32 to 8 characters passed the
     * comparison of names alone — and truncated `continued_as_new`, eighteen characters, on
     * MySQL. The declared type and the nullability therefore enter the comparison.
     *
     * @return array<string, string>
     */
    private static function columns(Connection $connection, string $table): array
    {
        $shape = [];
        foreach ($connection->getSchemaBuilder()->getColumns($table) as $column) {
            $shape[(string) $column['name']] = \sprintf(
                '%s%s',
                $column['type'] ?? $column['type_name'] ?? '?',
                ($column['nullable'] ?? false) ? ' null' : '',
            );
        }
        ksort($shape);

        return $shape;
    }
}
