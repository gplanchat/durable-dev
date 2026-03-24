<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalActivityTransport implements ActivityTransportInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName = 'durable_activity_outbox',
    ) {
    }

    public function enqueue(ActivityMessage $message): void
    {
        $this->connection->insert($this->tableName, [
            'execution_id' => $message->executionId,
            'activity_id' => $message->activityId,
            'activity_name' => $message->activityName,
            'payload' => json_encode($message->payload, \JSON_THROW_ON_ERROR),
            'metadata' => json_encode($message->metadata, \JSON_THROW_ON_ERROR),
        ], [
            'payload' => ParameterType::STRING,
            'metadata' => ParameterType::STRING,
        ]);
    }

    public function dequeue(): ?ActivityMessage
    {
        return $this->connection->transactional(function () {
            $table = $this->connection->quoteIdentifier($this->tableName);
            $platform = $this->connection->getDatabasePlatform();
            $isMySQL = $platform instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

            $sql = 'SELECT id, execution_id, activity_id, activity_name, payload, metadata FROM '.$table
                .' ORDER BY id ASC LIMIT 1';
            if ($isMySQL) {
                $sql .= ' FOR UPDATE SKIP LOCKED';
            }

            $row = $this->connection->fetchAssociative($sql);
            if (false === $row) {
                return null;
            }

            $this->connection->delete($this->tableName, ['id' => $row['id']]);

            return new ActivityMessage(
                $row['execution_id'],
                $row['activity_id'],
                $row['activity_name'],
                json_decode($row['payload'], true, 512, \JSON_THROW_ON_ERROR),
                json_decode($row['metadata'], true, 512, \JSON_THROW_ON_ERROR) ?: [],
            );
        });
    }

    public function isEmpty(): bool
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM '.$this->connection->quoteIdentifier($this->tableName),
        );

        return 0 === (int) $count;
    }

    public function removePendingFor(string $executionId, string $activityId): bool
    {
        $table = $this->connection->quoteIdentifier($this->tableName);

        return $this->connection->executeStatement(
            'DELETE FROM '.$table.' WHERE execution_id = ? AND activity_id = ?',
            [$executionId, $activityId],
        ) > 0;
    }

    public function createSchema(): void
    {
        $schema = $this->connection->createSchemaManager();
        $table = new \Doctrine\DBAL\Schema\Table($this->tableName);
        $table->addColumn('id', 'bigint', ['autoincrement' => true]);
        $table->addColumn('execution_id', 'string', ['length' => 36]);
        $table->addColumn('activity_id', 'string', ['length' => 36]);
        $table->addColumn('activity_name', 'string', ['length' => 255]);
        $table->addColumn('payload', 'text');
        $table->addColumn('metadata', 'text');
        $table->setPrimaryKey(['id']);
        $table->addIndex(['execution_id']);

        $schema->createTable($table);
    }
}
