<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * Tables du backend DBAL : journal, métadonnées d'exécution, lien parent/enfant.
 *
 * L'auto-création suit le modèle du transport Doctrine de Messenger : la première écriture
 * crée ce qui manque. Pas de doctrine/migrations — la forme est figée par ce fichier.
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
    ) {
    }

    /**
     * Idempotent : ne crée que les tables absentes, et ne le vérifie qu'une fois par processus.
     */
    public function ensure(): void
    {
        if ($this->ensured) {
            return;
        }
        $this->ensured = true;

        $schemaManager = $this->connection->createSchemaManager();
        $existing = array_values(array_filter(
            [$this->eventsTable, $this->metadataTable, $this->parentLinkTable],
            static fn (string $table): bool => $schemaManager->tablesExist([$table]),
        ));

        $schema = new Schema();
        $this->addToSchema($schema, $existing);

        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    /**
     * Déclare les tables manquantes ; branché aussi sur `configureSchema` côté bundle.
     *
     * @param list<string> $skip tables déjà présentes
     */
    public function addToSchema(Schema $schema, array $skip = []): void
    {
        if (!\in_array($this->eventsTable, $skip, true)) {
            $events = $schema->createTable($this->eventsTable);
            // Auto-increment : `readStream()` promet l'ordre d'insertion, l'id le porte.
            $events->addColumn('id', Types::BIGINT)->setAutoincrement(true);
            $events->addColumn('execution_id', Types::STRING, ['length' => 128]);
            $events->addColumn('event_type', Types::STRING, ['length' => 255]);
            $events->addColumn('payload', Types::TEXT);
            $events->addColumn('recorded_at', Types::DATETIME_IMMUTABLE);
            // setPrimaryKey() est déprécié en DBAL 4.3, mais son remplaçant n'existe pas en DBAL 3 :
            // le paquet supporte les deux majeures, donc on garde l'appel commun.
            $events->setPrimaryKey(['id']);
            $events->addIndex(['execution_id'], $this->eventsTable.'_execution_idx');
        }

        if (!\in_array($this->metadataTable, $skip, true)) {
            $metadata = $schema->createTable($this->metadataTable);
            $metadata->addColumn('execution_id', Types::STRING, ['length' => 128]);
            $metadata->addColumn('workflow_type', Types::STRING, ['length' => 255]);
            $metadata->addColumn('payload', Types::TEXT);
            $metadata->addColumn('completed', Types::BOOLEAN, ['default' => false]);
            $metadata->setPrimaryKey(['execution_id']);
        }

        if (!\in_array($this->parentLinkTable, $skip, true)) {
            $link = $schema->createTable($this->parentLinkTable);
            $link->addColumn('child_execution_id', Types::STRING, ['length' => 128]);
            $link->addColumn('parent_execution_id', Types::STRING, ['length' => 128]);
            $link->setPrimaryKey(['child_execution_id']);
            $link->addIndex(['parent_execution_id'], $this->parentLinkTable.'_parent_idx');
        }
    }
}
