<?php

declare(strict_types=1);

namespace unit\Bridge;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection as IlluminateConnection;

/**
 * The database the SQL conformance suites run against (#327).
 *
 * In-memory SQLite by default. `DURABLE_TEST_DSN` points them at a real server instead
 * (`mysql://root:root@127.0.0.1:3306/durable_test`, `pgsql://…`): SQLite answers some questions
 * differently, such as the rows an UPDATE reports when nothing changes, and a trap that only
 * MySQL springs stays invisible without it. A server database outlives the test, so every table
 * is dropped first; point the variable at a database kept for this.
 */
final class SqlTestDatabase
{
    public static function dbal(): DbalConnection
    {
        $dsn = getenv('DURABLE_TEST_DSN');
        if (false === $dsn || '' === $dsn) {
            return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        }

        $connection = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']))->parse($dsn));
        $schema = $connection->createSchemaManager();
        foreach ($schema->listTableNames() as $table) {
            $schema->dropTable($table);
        }

        return $connection;
    }

    public static function illuminate(): IlluminateConnection
    {
        $dsn = getenv('DURABLE_TEST_DSN');
        $capsule = new Manager();
        $capsule->addConnection(false === $dsn || '' === $dsn
            ? ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']
            : ['url' => $dsn, 'prefix' => '']);
        $connection = $capsule->getConnection();

        if (false !== $dsn && '' !== $dsn) {
            $connection->getSchemaBuilder()->dropAllTables();
        }

        return $connection;
    }
}
