<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * Tables of the DBAL backend: journal, execution metadata, parent/child link, run catalogue.
 *
 * Two ways to get them, and Messenger's Doctrine transport has both:
 *
 * - **Auto-creation** ({@see ensure()}): the first write creates what is missing. Handy in
 *   development, and it is the default.
 * - **Declaration** ({@see configureSchema()}): the tables join the schema Doctrine builds, so
 *   `doctrine:schema:update` and `doctrine:migrations:diff` know about them. Without it the
 *   tooling sees them as orphans and **generates their removal**: a journal of durable executions
 *   erased by a migration nobody read closely.
 *
 * The two together tread on each other as soon as migrations hold the schema. `auto_setup` then
 * turns auto-creation off, exactly as the Doctrine transport does.
 *
 * @see DUR030
 */
final class DurableSchema
{
    private bool $ensured = false;

    /**
     * @var array<string, bool>
     */
    private array $runsTableColumns = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $eventsTable = 'durable_events',
        private readonly string $metadataTable = 'durable_workflow_metadata',
        private readonly string $parentLinkTable = 'durable_child_workflow_parent_link',
        private readonly string $runsTable = 'durable_workflow_runs',
        private readonly bool $autoSetup = true,
    ) {}

    /**
     * The journal's table as configured: whoever reads the journal without being handed its store
     * reads this one, not the default.
     */
    public function eventsTable(): string
    {
        return $this->eventsTable;
    }

    /**
     * Idempotent: creates only the missing tables, and checks that only once per process.
     */
    public function ensure(): void
    {
        if (!$this->autoSetup || $this->ensured) {
            return;
        }

        $this->create(refuseInsideTransaction: true);
        // Only once it held: after a refusal, the next write tries again.
        $this->ensured = true;
    }

    /**
     * Creates the missing tables whatever `auto_setup` says: `durable:setup` is how they get
     * created when it is off. Call it outside any transaction: on MySQL the DDL commits it.
     */
    public function setup(): void
    {
        $this->create(refuseInsideTransaction: false);
    }

    private function create(bool $refuseInsideTransaction): void
    {
        $tables = [$this->eventsTable, $this->metadataTable, $this->parentLinkTable, $this->runsTable];
        $schemaManager = $this->connection->createSchemaManager();
        $existing = $this->unfiltered(static fn(): array => array_values(array_filter(
            $tables,
            static fn(string $table): bool => $schemaManager->tablesExist([$table]),
        )));
        if ($existing === $tables) {
            return;
        }

        // MySQL commits an open transaction on DDL, and the caller's commit then fails with
        // "There is no active transaction". Messenger's Doctrine transport refuses here too.
        if ($refuseInsideTransaction && $this->connection->isTransactionActive()) {
            throw DurableSchemaMissing::insideTransaction(array_values(array_diff($tables, $existing)));
        }

        $schema = new Schema();
        $this->addToSchema($schema, $existing);

        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    /**
     * Whether the runs table has the `picked_up_at` column. Tables created before #447 do not, and
     * nothing alters an existing table: without the column, the pickup is simply not recorded and the
     * run list does not tell a run waiting for a worker. Asked once per process.
     */
    public function runsTableTracksPickup(): bool
    {
        return $this->runsTableHas('picked_up_at');
    }

    /**
     * Whether the runs table has the `waiting_on` column (#324), under the same rule as
     * {@see runsTableTracksPickup()}: without it, the wait is not recorded and the list does not say it.
     */
    public function runsTableTracksWait(): bool
    {
        return $this->runsTableHas('waiting_on');
    }

    private function runsTableHas(string $column): bool
    {
        if (isset($this->runsTableColumns[$column])) {
            return $this->runsTableColumns[$column];
        }

        $schemaManager = $this->connection->createSchemaManager();
        $columns = $this->unfiltered(fn(): ?array => $schemaManager->tablesExist([$this->runsTable])
            ? $schemaManager->listTableColumns($this->runsTable)
            : null);
        // No table yet is no answer: a worker may boot before the migrations run.
        if (null === $columns) {
            return false;
        }

        return $this->runsTableColumns[$column] = \array_key_exists($column, array_change_key_case($columns));
    }

    /**
     * Runs a probe of Durable's own tables with the connection's schema assets filter off. DBAL
     * applies that filter to `tablesExist()` as well, and an application that rejects `durable_*`
     * there, to keep its tooling off these tables, would make them look missing forever: recreated
     * on every `ensure()`, refused inside every transaction (#339). The filter is restored after.
     *
     * @template T
     *
     * @param callable(): T $probe
     *
     * @return T
     */
    private function unfiltered(callable $probe): mixed
    {
        $configuration = $this->connection->getConfiguration();
        $filter = $configuration->getSchemaAssetsFilter();
        $configuration->setSchemaAssetsFilter(static fn(): bool => true);

        try {
            return $probe();
        } finally {
            $configuration->setSchemaAssetsFilter($filter);
        }
    }

    /**
     * Adds the missing tables to the schema Doctrine builds, so the tooling knows them instead of
     * taking them for orphans to drop.
     *
     * The journal may live on a connection other than the ORM's. Declaring these tables there would
     * create, in the application's database, tables that do not belong to it, and would leave the
     * tooling offering to drop, in the journal's database, the ones that do. Hence the same guard as
     * the adapters upstream: the same connection, or the same database proven by the probe.
     *
     * A table the schema assets filter rejects is left out, as upstream's listeners do: the tooling
     * does not compare it, and declaring it would yield a `CREATE TABLE` in every diff.
     *
     * @param \Closure(\Closure(string): mixed): bool $isSameDatabase
     * @param (callable(string): bool)|null          $accepts        the connection's schema assets filter
     *
     * @return Schema the schema, completed
     */
    public function configureSchema(Schema $schema, Connection $forConnection, \Closure $isSameDatabase, ?callable $accepts = null): Schema
    {
        $tables = [$this->eventsTable, $this->metadataTable, $this->parentLinkTable, $this->runsTable];
        $skip = array_values(array_filter(
            $tables,
            static fn(string $table): bool => $schema->hasTable($table) || (null !== $accepts && !$accepts($table)),
        ));
        // Before the probe: it writes a table on the ORM's connection, for nothing if all is skipped.
        if ($skip === $tables) {
            return $schema;
        }

        if ($forConnection !== $this->connection && !$isSameDatabase($this->connection->executeStatement(...))) {
            return $schema;
        }

        $this->addToSchema($schema, $skip);

        return $schema;
    }

    /**
     * Declares the missing tables in the schema it is given.
     *
     * @param list<string> $skip tables already present
     */
    public function addToSchema(Schema $schema, array $skip = []): void
    {
        if (!\in_array($this->eventsTable, $skip, true)) {
            $events = $schema->createTable($this->eventsTable);
            // Auto-increment: `readStream()` promises insertion order, the id carries it.
            $events->addColumn('id', Types::BIGINT)->setAutoincrement(true);
            $events->addColumn('execution_id', Types::STRING, ['length' => 128]);
            $events->addColumn('event_type', Types::STRING, ['length' => 255]);
            $events->addColumn('payload', Types::TEXT);
            $events->addColumn('recorded_at', Types::DATETIME_IMMUTABLE);
            // setPrimaryKey() is deprecated in DBAL 4.3, but its replacement does not exist in
            // DBAL 3: the package supports both majors, so we keep the call they share.
            $events->setPrimaryKey(['id']);
            $events->addIndex(['execution_id'], $this->eventsTable . '_execution_idx');
        }

        if (!\in_array($this->metadataTable, $skip, true)) {
            $metadata = $schema->createTable($this->metadataTable);
            $metadata->addColumn('execution_id', Types::STRING, ['length' => 128]);
            $metadata->addColumn('workflow_type', Types::STRING, ['length' => 255]);
            $metadata->addColumn('payload', Types::TEXT);
            $metadata->addColumn('completed', Types::BOOLEAN, ['default' => false]);
            $metadata->setPrimaryKey(['execution_id']);
        }

        if (!\in_array($this->runsTable, $skip, true)) {
            // Read projection: the journal is written at every step and read by execution id,
            // while a dashboard reads across it and orders by date. Two access patterns, and
            // `durable_events` is only indexed on `execution_id` — listing from it would be a
            // scan per page, growing with the total number of events ever written.
            $runs = $schema->createTable($this->runsTable);
            $runs->addColumn('execution_id', Types::STRING, ['length' => 128]);
            $runs->addColumn('workflow_type', Types::STRING, ['length' => 255]);
            $runs->addColumn('status', Types::STRING, ['length' => 32]);
            $runs->addColumn('started_at', Types::DATETIME_IMMUTABLE);
            $runs->addColumn('ended_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            // When a worker first picked the run up (#447); null while the run waits for one.
            $runs->addColumn('picked_up_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            // What the run last suspended on (#324); read only while it is running.
            $runs->addColumn('waiting_on', Types::TEXT, ['notnull' => false]);
            $runs->setPrimaryKey(['execution_id']);
            $runs->addIndex(['started_at'], $this->runsTable . '_started_idx');
            // The run list filters on status and orders by start (#339).
            $runs->addIndex(['status', 'started_at'], $this->runsTable . '_status_started_idx');
        }

        if (!\in_array($this->parentLinkTable, $skip, true)) {
            $link = $schema->createTable($this->parentLinkTable);
            $link->addColumn('child_execution_id', Types::STRING, ['length' => 128]);
            $link->addColumn('parent_execution_id', Types::STRING, ['length' => 128]);
            $link->setPrimaryKey(['child_execution_id']);
            $link->addIndex(['parent_execution_id'], $this->parentLinkTable . '_parent_idx');
        }
    }
}
