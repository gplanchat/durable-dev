<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\DurableModule\Schema\JournalSchema;
use Gplanchat\DurableModule\Schema\JournalTableMissing;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture/magento-resource-connection.php';
require_once __DIR__ . '/Fixture/magento-ddl-table.php';

/**
 * `durable:setup` creates the tables and only adds what is missing (#746, DUR056). The real DDL
 * runs on the Magento bench against MySQL; here the adapter is recorded, so the decisions are
 * pinned: which table is created, which column is added, and that an open transaction creates nothing.
 */
final class TheJournalSchemaIsCreatedBySetupOnlyTest extends TestCase
{
    public function testAnEmptyDatabaseGetsEveryTableAndASecondRunDoesNothing(): void
    {
        $adapter = $this->adapter();
        $schema = new JournalSchema($adapter);

        $first = $schema->setup();
        self::assertSame(
            ['durable_events', 'durable_execution_heads', 'durable_workflow_metadata', 'durable_child_workflow_parent_link', 'durable_workflow_runs', 'durable_queue'],
            array_keys($adapter->tables),
        );
        self::assertCount(6, $first);
        self::assertContains('waiting_on', $adapter->tables['durable_workflow_runs']);
        self::assertContains('picked_up_at', $adapter->tables['durable_workflow_runs']);
        self::assertContains('leased_until', $adapter->tables['durable_queue']);

        self::assertSame([], $schema->setup());
        self::assertSame(6, $adapter->created);
        self::assertSame([], $adapter->added);
    }

    public function testATableFromAnOlderVersionGetsOnlyItsMissingColumn(): void
    {
        $adapter = $this->adapter();
        (new JournalSchema($adapter))->setup();
        $adapter->tables['durable_workflow_runs'] = array_values(array_diff($adapter->tables['durable_workflow_runs'], ['waiting_on']));

        self::assertSame(['added durable_workflow_runs.waiting_on'], (new JournalSchema($adapter))->setup());
        self::assertSame(['durable_workflow_runs.waiting_on'], $adapter->added);
        self::assertSame(6, $adapter->created, 'the existing table, and its rows, are not recreated');
    }

    public function testSetupInsideATransactionThrowsAndCreatesNothing(): void
    {
        $adapter = $this->adapter();
        $adapter->level = 1;

        try {
            (new JournalSchema($adapter))->setup();
            self::fail('setup() must refuse inside a transaction.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('transaction', $e->getMessage());
        }

        self::assertSame([], $adapter->tables);
    }

    public function testAStoreUsedBeforeSetupNamesTheMissingTableAndTheCommand(): void
    {
        $adapter = $this->adapter();

        try {
            (new JournalSchema($adapter))->assertInstalled();
            self::fail('assertInstalled() must throw before durable:setup.');
        } catch (JournalTableMissing $e) {
            self::assertStringContainsString('"durable_events"', $e->getMessage());
            self::assertStringContainsString('durable:setup', $e->getMessage());
        }

        self::assertSame([], $adapter->tables, 'a store never issues DDL');
    }

    private function adapter(): object
    {
        return new class implements AdapterInterface {
            /** @var array<string, list<string>> */
            public array $tables = [];
            public int $level = 0;
            public int $created = 0;
            /** @var list<string> */
            public array $added = [];

            public function getTransactionLevel(): int
            {
                return $this->level;
            }

            public function isTableExists(string $name): bool
            {
                return isset($this->tables[$name]);
            }

            public function tableColumnExists(string $table, string $column): bool
            {
                return \in_array($column, $this->tables[$table], true);
            }

            public function newTable(string $name): Table
            {
                return new Table($name);
            }

            public function createTable(Table $table): void
            {
                $this->tables[$table->name] = $table->columns;
                ++$this->created;
            }

            /**
             * @param array<string, mixed> $definition
             */
            public function addColumn(string $table, string $column, array $definition): void
            {
                $this->tables[$table][] = $column;
                $this->added[] = $table . '.' . $column;
            }
        };
    }
}
