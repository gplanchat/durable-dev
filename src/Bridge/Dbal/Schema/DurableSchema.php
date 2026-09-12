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

    public function __construct(
        private readonly Connection $connection,
        private readonly string $eventsTable = 'durable_events',
        private readonly string $metadataTable = 'durable_workflow_metadata',
        private readonly string $parentLinkTable = 'durable_child_workflow_parent_link',
        private readonly string $runsTable = 'durable_workflow_runs',
        private readonly bool $autoSetup = true,
    ) {}

    /**
     * Idempotent: creates only the missing tables, and checks that only once per process.
     */
    public function ensure(): void
    {
        if (!$this->autoSetup || $this->ensured) {
            return;
        }
        $this->ensured = true;

        $schemaManager = $this->connection->createSchemaManager();
        $existing = array_values(array_filter(
            [$this->eventsTable, $this->metadataTable, $this->parentLinkTable, $this->runsTable],
            static fn(string $table): bool => $schemaManager->tablesExist([$table]),
        ));

        $schema = new Schema();
        $this->addToSchema($schema, $existing);

        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
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
     * @param \Closure(\Closure(string): mixed): bool $isSameDatabase
     *
     * @return Schema the schema, completed
     */
    public function configureSchema(Schema $schema, Connection $forConnection, \Closure $isSameDatabase): Schema
    {
        if ($forConnection !== $this->connection && !$isSameDatabase($this->connection->executeStatement(...))) {
            return $schema;
        }

        $this->addToSchema($schema, array_values(array_filter(
            [$this->eventsTable, $this->metadataTable, $this->parentLinkTable, $this->runsTable],
            static fn(string $table): bool => $schema->hasTable($table),
        )));

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
            $runs->setPrimaryKey(['execution_id']);
            $runs->addIndex(['started_at'], $this->runsTable . '_started_idx');
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
