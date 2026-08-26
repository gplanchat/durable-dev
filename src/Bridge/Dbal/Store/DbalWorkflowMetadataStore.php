<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Store;

use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Store\WorkflowMetadataStore;

/**
 * Métadonnées de reprise persistées en SQL : sans elles, un worker qui reçoit un
 * {@see \Gplanchat\Durable\Transport\ResumeWorkflowMessage} ne sait pas quel workflow rejouer.
 *
 * @see DUR030
 */
final class DbalWorkflowMetadataStore implements WorkflowMetadataStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_workflow_metadata',
    ) {}

    public function save(string $executionId, string $workflowType, array $payload): void
    {
        $this->schema->ensure();

        $row = [
            'workflow_type' => $workflowType,
            'payload' => json_encode($payload, \JSON_THROW_ON_ERROR),
            'completed' => false,
        ];

        // Le type de `completed` est déclaré à chaque écriture : sans lui, PDO lie un `false` PHP
        // comme chaîne vide, et MySQL en mode strict refuse `''` pour une colonne entière. SQLite
        // l'accepte, ce qui laisse la faute invisible à toute la suite unitaire.
        $types = ['completed' => 'boolean'];

        // `save()` est aussi appelé pour repartir d'un continue-as-new : upsert, pas insert.
        //
        // L'existence est demandée plutôt que déduite du nombre de lignes affectées par l'UPDATE :
        // SQLite compte les lignes *correspondantes*, MySQL les lignes *modifiées*. Ré-enregistrer
        // des métadonnées identiques donne donc 0 sur MySQL, et l'INSERT qui suivait violait la
        // clé primaire. Un aller-retour de plus, mais le même comportement partout.
        $exists = false !== $this->connection->fetchOne(
            \sprintf('SELECT 1 FROM %s WHERE execution_id = ?', $this->table),
            [$executionId],
        );

        if ($exists) {
            $this->connection->update($this->table, $row, ['execution_id' => $executionId], $types);
        } else {
            $this->connection->insert($this->table, $row + ['execution_id' => $executionId], $types);
        }
    }

    public function markCompleted(string $executionId): void
    {
        $this->schema->ensure();

        $this->connection->update($this->table, ['completed' => true], ['execution_id' => $executionId], ['completed' => 'boolean']);
    }

    public function get(string $executionId): ?array
    {
        $this->schema->ensure();

        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT workflow_type, payload, completed FROM %s WHERE execution_id = ?', $this->table),
            [$executionId],
        );

        if (false === $row) {
            return null;
        }

        $payload = json_decode((string) $row['payload'], true, 512, \JSON_THROW_ON_ERROR);

        return [
            'workflowType' => (string) $row['workflow_type'],
            'payload' => \is_array($payload) ? $payload : [],
            'completed' => (bool) $row['completed'],
        ];
    }

    public function hasActiveWorkflowMetadata(string $executionId): bool
    {
        $metadata = $this->get($executionId);

        return null !== $metadata && true !== ($metadata['completed'] ?? false);
    }

    public function delete(string $executionId): void
    {
        $this->schema->ensure();

        $this->connection->delete($this->table, ['execution_id' => $executionId]);
    }
}
