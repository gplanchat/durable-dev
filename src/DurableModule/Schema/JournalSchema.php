<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Schema;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;

/**
 * The Durable tables on the journal's connection, declared with `Ddl\Table` (DUR056).
 *
 * Only `durable:setup` creates them: a store's write runs in a transaction, and MySQL commits it
 * on DDL. {@see setup()} is idempotent and additive: it creates a missing table, adds a missing
 * column, and touches nothing else, so a later version appends a column or a table to
 * {@see TABLES} and the same command upgrades. It does not rename, retype or drop.
 *
 * Every time column is DATETIME(3): the queue compares `available_at` and `leased_until` with the
 * clock, and a lease of a few seconds needs more than whole seconds. `Ddl\Table` has no precision
 * for DATETIME, so the column is created, then widened with a raw `ALTER TABLE ... MODIFY COLUMN` (`modifyColumn()` goes through Magento's schema listener, which rejects a string definition) when
 * `information_schema` reports less than 3. The widening keeps the rows and runs on a table that
 * already exists too, so a journal created at DATETIME(0) is upgraded by the next `durable:setup`.
 *
 * ponytail: no `table_prefix` (the journal's connection is dedicated), and no locks table until
 * #732 measures the resume lock. Add the table here when it does.
 */
final class JournalSchema
{
    /**
     * A column is `[name, type, size, options]`; an index is `[name, columns]`.
     *
     * @var array<string, array{columns: list<array{0: string, 1: string, 2: int|string|null, 3: array<string, mixed>}>, indexes?: list<array{0: string, 1: list<string>}>}>
     */
    private const TABLES = [
        'durable_events' => [
            'columns' => [
                // Auto-increment: `readStream()` promises insertion order, the id carries it.
                ['id', Table::TYPE_BIGINT, null, ['identity' => true, 'primary' => true, 'nullable' => false, 'unsigned' => true]],
                ['execution_id', Table::TYPE_TEXT, 128, ['nullable' => false]],
                ['event_type', Table::TYPE_TEXT, 255, ['nullable' => false]],
                ['payload', Table::TYPE_TEXT, '16M', ['nullable' => false]],
                ['recorded_at', Table::TYPE_DATETIME, null, ['nullable' => false]],
            ],
            'indexes' => [['durable_events_execution_idx', ['execution_id']]],
        ],
        // The newest pass epoch of each execution (DUR053); an absent row is epoch 0.
        'durable_execution_heads' => [
            'columns' => [
                ['execution_id', Table::TYPE_TEXT, 128, ['primary' => true, 'nullable' => false]],
                ['epoch', Table::TYPE_BIGINT, null, ['nullable' => false, 'unsigned' => true]],
            ],
        ],
        'durable_workflow_metadata' => [
            'columns' => [
                ['execution_id', Table::TYPE_TEXT, 128, ['primary' => true, 'nullable' => false]],
                ['workflow_type', Table::TYPE_TEXT, 255, ['nullable' => false]],
                ['payload', Table::TYPE_TEXT, '16M', ['nullable' => false]],
                ['completed', Table::TYPE_BOOLEAN, null, ['nullable' => false, 'default' => 0]],
            ],
        ],
        'durable_child_workflow_parent_link' => [
            'columns' => [
                ['child_execution_id', Table::TYPE_TEXT, 128, ['primary' => true, 'nullable' => false]],
                ['parent_execution_id', Table::TYPE_TEXT, 128, ['nullable' => false]],
            ],
            'indexes' => [['durable_child_workflow_parent_link_parent_idx', ['parent_execution_id']]],
        ],
        // The run catalogue: what the admin lists, ordered by start and filtered by status.
        'durable_workflow_runs' => [
            'columns' => [
                ['execution_id', Table::TYPE_TEXT, 128, ['primary' => true, 'nullable' => false]],
                ['workflow_type', Table::TYPE_TEXT, 255, ['nullable' => false]],
                ['status', Table::TYPE_TEXT, 32, ['nullable' => false]],
                ['started_at', Table::TYPE_DATETIME, null, ['nullable' => false]],
                ['ended_at', Table::TYPE_DATETIME, null, ['nullable' => true]],
                ['picked_up_at', Table::TYPE_DATETIME, null, ['nullable' => true]],
                ['waiting_on', Table::TYPE_TEXT, '64k', ['nullable' => true]],
            ],
            'indexes' => [
                ['durable_workflow_runs_started_idx', ['started_at']],
                ['durable_workflow_runs_status_started_idx', ['status', 'started_at']],
            ],
        ],
        // A message waits until `available_at`; a take sets `leased_until`, and a message whose lease
        // ran out is taken again (#731).
        'durable_queue' => [
            'columns' => [
                ['id', Table::TYPE_BIGINT, null, ['identity' => true, 'primary' => true, 'nullable' => false, 'unsigned' => true]],
                ['queue_name', Table::TYPE_TEXT, 64, ['nullable' => false]],
                ['body', Table::TYPE_TEXT, '16M', ['nullable' => false]],
                ['available_at', Table::TYPE_DATETIME, null, ['nullable' => false]],
                ['leased_until', Table::TYPE_DATETIME, null, ['nullable' => true]],
            ],
            'indexes' => [['durable_queue_take_idx', ['queue_name', 'available_at']]],
        ],
    ];

    private const TIME_PRECISION = 3;

    private bool $installed = false;

    public function __construct(private readonly AdapterInterface $connection) {}

    /**
     * Creates the missing tables and the missing columns.
     *
     * @return list<string> what it did, one line each; empty when the schema was complete
     */
    public function setup(): array
    {
        if (0 !== $this->connection->getTransactionLevel()) {
            throw new \RuntimeException('durable:setup runs DDL, which MySQL commits implicitly: it refuses to run inside an open transaction. Run it from its own process, outside any transaction.');
        }

        $done = [];
        foreach (self::TABLES as $name => $definition) {
            if (!$this->connection->isTableExists($name)) {
                $table = $this->connection->newTable($name)->setComment($name);
                foreach ($definition['columns'] as $column) {
                    $table->addColumn($column[0], $column[1], $column[2], $column[3], ucfirst($column[0]));
                }
                foreach ($definition['indexes'] ?? [] as [$index, $columns]) {
                    $table->addIndex($index, $columns);
                }
                $this->connection->createTable($table);
                $done[] = \sprintf('created %s', $name);

                continue;
            }

            foreach ($definition['columns'] as $column) {
                if ($this->connection->tableColumnExists($name, $column[0])) {
                    continue;
                }
                $this->connection->addColumn($name, $column[0], [
                    'TYPE' => $column[1],
                    'LENGTH' => $column[2],
                    'COMMENT' => ucfirst($column[0]),
                ] + array_change_key_case($column[3], CASE_UPPER));
                $done[] = \sprintf('added %s.%s', $name, $column[0]);
            }
        }

        foreach (self::TABLES as $name => $definition) {
            foreach ($definition['columns'] as $column) {
                if (Table::TYPE_DATETIME === $column[1] && $this->precision($name, $column[0]) < self::TIME_PRECISION) {
                    // A raw ALTER: `modifyColumn()` with a string definition fails in Magento's schema listener.
                    $this->connection->query(\sprintf(
                        'ALTER TABLE %s MODIFY COLUMN %s DATETIME(%d) %s COMMENT %s',
                        $this->connection->quoteIdentifier($name),
                        $this->connection->quoteIdentifier($column[0]),
                        self::TIME_PRECISION,
                        $column[3]['nullable'] ? 'NULL' : 'NOT NULL',
                        $this->connection->quote(ucfirst($column[0])),
                    ));
                    $this->connection->resetDdlCache($name);
                    $done[] = \sprintf('widened %s.%s to DATETIME(%d)', $name, $column[0], self::TIME_PRECISION);
                }
            }
        }

        return $done;
    }

    private function precision(string $table, string $column): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );
    }

    /**
     * What a store calls before its first query: a missing table is named, never created.
     */
    public function assertInstalled(): void
    {
        if ($this->installed) {
            return;
        }

        foreach (array_keys(self::TABLES) as $name) {
            if (!$this->connection->isTableExists($name)) {
                throw JournalTableMissing::named($name);
            }
        }
        $this->installed = true;
    }
}
