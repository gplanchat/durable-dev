<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\SchemaListener;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Bundle\SchemaListener\DurableSchemaListener;
use PHPUnit\Framework\TestCase;

/**
 * The production path of the declaration: the listener, hooked on `postGenerateSchema`, with the
 * real "same database" probe.
 *
 * The `DurableSchema` tests pass the probe as a parameter and therefore prove the shape of the
 * schema, never the decision to declare. That decision is what reopens or closes the finding: a
 * probe that always answers `false` lets `doctrine:migrations:diff` regenerate its `DROP TABLE`
 * as soon as the journal has its own connection.
 */
final class DurableSchemaListenerTest extends TestCase
{
    private const TABLES = [
        'durable_events',
        'durable_workflow_metadata',
        'durable_child_workflow_parent_link',
        'durable_workflow_runs',
    ];

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    public function testTheSameConnectionDeclaresTheTables(): void
    {
        $connection = self::inMemory();
        $schema = new Schema();

        (new DurableSchemaListener(new DurableSchema($connection)))
            ->postGenerateSchema($this->event($connection, $schema));

        foreach (self::TABLES as $table) {
            self::assertTrue($schema->hasTable($table), \sprintf('%s must be declared', $table));
        }
    }

    /**
     * Two distinct `Connection` objects on the same file: this is the case the probe exists to
     * settle, and the one a probe wired to `false` treated as another database.
     */
    public function testTwoConnectionsOnTheSameDatabaseDeclareTheTables(): void
    {
        $file = $this->file();
        $journal = self::onFile($file);
        $orm = self::onFile($file);
        $schema = new Schema();

        (new DurableSchemaListener(new DurableSchema($journal)))
            ->postGenerateSchema($this->event($orm, $schema));

        self::assertCount(\count(self::TABLES), $schema->getTables(), 'the probe must recognise the same database');
    }

    public function testTwoDistinctDatabasesDeclareNothing(): void
    {
        $journal = self::onFile($this->file());
        $orm = self::onFile($this->file());
        $schema = new Schema();

        (new DurableSchemaListener(new DurableSchema($journal)))
            ->postGenerateSchema($this->event($orm, $schema));

        self::assertSame([], $schema->getTables(), 'no table may join the schema of another database');
    }

    /**
     * An application that excluded `durable_*` in `schema_filter` got a perpetual `CREATE TABLE`
     * from `migrations:diff`: the listener added what the filter keeps out of the comparison (#339).
     */
    public function testATableTheSchemaFilterRejectsIsNotDeclared(): void
    {
        $connection = self::inMemory();
        $connection->getConfiguration()->setSchemaAssetsFilter(static fn(string $name): bool => !str_starts_with($name, 'durable_'));
        $schema = new Schema();

        (new DurableSchemaListener(new DurableSchema($connection)))
            ->postGenerateSchema($this->event($connection, $schema));

        self::assertSame([], $schema->getTables());
    }

    public function testOnlyTheRejectedTablesAreLeftOut(): void
    {
        $connection = self::inMemory();
        $connection->getConfiguration()->setSchemaAssetsFilter(static fn(string $name): bool => 'durable_events' !== $name);
        $schema = new Schema();

        (new DurableSchemaListener(new DurableSchema($connection)))
            ->postGenerateSchema($this->event($connection, $schema));

        self::assertFalse($schema->hasTable('durable_events'));
        self::assertCount(\count(self::TABLES) - 1, $schema->getTables());
    }

    private function event(Connection $connection, Schema $schema): GenerateSchemaEventArgs
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new GenerateSchemaEventArgs($em, $schema);
    }

    private static function inMemory(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    private static function onFile(string $path): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
    }

    private function file(): string
    {
        $path = \sprintf('%s/durable-schema-%s.sqlite', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $this->files[] = $path;

        return $path;
    }
}
