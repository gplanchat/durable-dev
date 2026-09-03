<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * Tables du backend DBAL : journal, métadonnées d'exécution, lien parent/enfant, catalogue de runs.
 *
 * Deux façons de les obtenir, et le transport Doctrine de Messenger a les deux :
 *
 * - **L'auto-création** ({@see ensure()}) : la première écriture crée ce qui manque. Pratique en
 *   développement, et c'est le défaut.
 * - **La déclaration** ({@see configureSchema()}) : les tables rejoignent le schéma que Doctrine
 *   construit, donc `doctrine:schema:update` et `doctrine:migrations:diff` les connaissent. Sans
 *   elle, l'outillage les voit comme orphelines et **génère leur suppression** — un journal
 *   d'exécutions durables effacé par une migration que personne n'a relue de près.
 *
 * Les deux ensemble se marchent dessus dès que les migrations tiennent le schéma : `auto_setup`
 * éteint alors l'auto-création, comme le fait le transport Doctrine.
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
     * Idempotent : ne crée que les tables absentes, et ne le vérifie qu'une fois par processus.
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
     * Ajoute au schéma que Doctrine construit les tables qui manquent, pour que l'outillage les
     * connaisse au lieu de les prendre pour des orphelines à supprimer.
     *
     * Le journal peut vivre sur une autre connexion que celle de l'ORM. Y déclarer ces tables
     * ferait créer, dans la base de l'application, des tables qui n'y sont pas — et laisserait
     * l'outillage proposer de supprimer, dans la base du journal, celles qui y sont. D'où la
     * même garde que les adaptateurs amont : même connexion, ou même base prouvée par la sonde.
     *
     * @param \Closure(\Closure(string): mixed): bool $isSameDatabase
     *
     * @return Schema le schéma, complété
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
     * Déclare les tables manquantes dans le schéma passé.
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
            // Projection de lecture : le journal est écrit à chaque pas et lu par id d'exécution,
            // un tableau de bord lit en travers et ordonne par date. Deux motifs d'accès, et
            // `durable_events` n'est indexée que sur `execution_id` — lister depuis lui serait un
            // balayage par page, croissant avec le nombre total d'événements jamais écrits.
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
