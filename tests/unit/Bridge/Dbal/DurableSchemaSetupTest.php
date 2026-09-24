<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Schema\DurableSchemaMissing;
use PHPUnit\Framework\TestCase;

/**
 * Who creates the tables, and when it must not (#339).
 *
 * Inside the caller's transaction, a `CREATE TABLE` is refused: MySQL commits the open
 * transaction implicitly on DDL, and the caller's own commit then fails with "There is no active
 * transaction". Messenger's Doctrine transport refuses in the same place, on every platform.
 */
final class DurableSchemaSetupTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testSetupCreatesTheTablesEvenWithAutoSetupOff(): void
    {
        (new DurableSchema($this->connection, autoSetup: false))->setup();

        self::assertCount(4, $this->durableTables());
    }

    public function testAutoSetupRefusesInsideTheCallersTransaction(): void
    {
        $schema = new DurableSchema($this->connection);
        $this->connection->beginTransaction();

        try {
            $schema->ensure();
            self::fail('ensure() was supposed to refuse inside an open transaction.');
        } catch (DurableSchemaMissing $refusal) {
            self::assertStringContainsString('durable:setup', $refusal->getMessage());
        }

        self::assertTrue($this->connection->isTransactionActive(), 'the caller keeps its transaction');
        self::assertSame([], $this->durableTables());
    }

    public function testAnInstalledSchemaIsNoReasonToRefuse(): void
    {
        (new DurableSchema($this->connection))->setup();
        $schema = new DurableSchema($this->connection);
        $this->connection->beginTransaction();

        $schema->ensure();

        self::assertTrue($this->connection->isTransactionActive());
    }

    public function testARefusalDoesNotStopTheNextAttempt(): void
    {
        $schema = new DurableSchema($this->connection);
        $this->connection->beginTransaction();

        try {
            $schema->ensure();
        } catch (DurableSchemaMissing) {
        }
        $this->connection->rollBack();

        $schema->ensure();

        self::assertCount(4, $this->durableTables());
    }

    /**
     * @return list<string>
     */
    private function durableTables(): array
    {
        $tables = array_values(array_filter(
            $this->connection->createSchemaManager()->listTableNames(),
            static fn(string $table): bool => str_starts_with($table, 'durable_'),
        ));
        sort($tables);

        return $tables;
    }
}
