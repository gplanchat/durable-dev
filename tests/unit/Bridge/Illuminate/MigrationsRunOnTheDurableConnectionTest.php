<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shipped migrations land where the stores write: `durable.connection` (DUR054).
 *
 * Without that, a journal on a connection of its own gets its tables from the stores' `ensure()`,
 * while `php artisan migrate` builds empty copies on the application's connection — and every later
 * schema migration keeps landing there, never on the journal's.
 *
 * `illuminate/filesystem` is not installed here, so the Migrator itself cannot be built. What it
 * does around `up()` is two lines, reproduced from `Migrator::runMethod()`: resolve
 * `$migration->getConnection()` (null meaning the default) and make it the resolver's default for
 * the duration of the call.
 */
final class MigrationsRunOnTheDurableConnectionTest extends TestCase
{
    private const TABLES = [
        'durable_events',
        'durable_workflow_metadata',
        'durable_workflow_runs',
        'durable_child_workflow_parent_link',
        'durable_execution_heads',
    ];

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function testTheTablesLandOnTheConfiguredConnectionAndNotOnTheApplications(): void
    {
        $db = self::migrate('durable');

        foreach (self::TABLES as $table) {
            self::assertTrue($db->connection('durable')->getSchemaBuilder()->hasTable($table), $table . ' on durable');
            self::assertFalse($db->connection('app')->getSchemaBuilder()->hasTable($table), $table . ' not on the application\'s');
        }
    }

    public function testWithoutAConnectionTheyLandOnTheDefaultOneAsBefore(): void
    {
        $db = self::migrate(null);

        foreach (self::TABLES as $table) {
            self::assertTrue($db->connection('app')->getSchemaBuilder()->hasTable($table), $table . ' on the default');
        }
    }

    #[DataProvider('migrationFiles')]
    public function testEveryMigrationNamesTheConfiguredConnection(string $file): void
    {
        self::container(self::databases(), 'durable');

        self::assertSame('durable', (require $file)->getConnection());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function migrationFiles(): iterable
    {
        foreach (glob(self::path() . '/*.php') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    private static function migrate(?string $durableConnection): DatabaseManager
    {
        $db = self::databases();
        self::container($db, $durableConnection);

        $files = glob(self::path() . '/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $migration = require $file;
            $previous = $db->getDefaultConnection();
            $db->setDefaultConnection($migration->getConnection() ?? $previous);

            try {
                $migration->up();
            } finally {
                $db->setDefaultConnection($previous);
            }
        }

        return $db;
    }

    private static function databases(): DatabaseManager
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'app');
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'durable');
        $capsule->getDatabaseManager()->setDefaultConnection('app');

        return $capsule->getDatabaseManager();
    }

    private static function container(DatabaseManager $db, ?string $durableConnection): void
    {
        $container = new Container();
        $container->instance('db', $db);
        $container->bind('db.schema', static fn(): object => $db->connection()->getSchemaBuilder());
        // `illuminate/config` is not installed here either: the facade only needs `get()`.
        $container->instance('config', new class ($durableConnection) {
            public function __construct(private readonly ?string $connection) {}

            public function get(string $key, mixed $default = null): mixed
            {
                return 'durable.connection' === $key ? $this->connection : $default;
            }
        });
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    private static function path(): string
    {
        return __DIR__ . '/../../../../src/Bridge/Illuminate/Migrations';
    }
}
