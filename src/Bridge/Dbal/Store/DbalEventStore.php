<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Store;

use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * Journal d'événements persisté en SQL — le pendant durable de
 * {@see \Gplanchat\Durable\Store\InMemoryEventStore}.
 *
 * La (dé)sérialisation passe entièrement par {@see EventDataMapper} : les lignes ont la même
 * forme que les enregistrements du journal Temporal, ce que le mapper documente déjà.
 *
 * ponytail: pas de colonne `sequence` — l'auto-increment porte l'ordre d'insertion.
 * L'exclusion mutuelle entre deux reprises concurrentes d'une même exécution est en amont,
 * dans {@see \Gplanchat\Bridge\Dbal\Messenger\SingleResumeLockMiddleware} ; sans elle, deux
 * workers rejoueraient la même exécution et dupliqueraient ses commandes.
 *
 * @see DUR030
 */
final class DbalEventStore implements EventStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_events',
    ) {
    }

    public function append(Event $event): void
    {
        $this->schema->ensure();

        $record = EventDataMapper::fromDomainEvent($event);

        $this->connection->insert($this->table, [
            'execution_id' => $record['execution_id'],
            'event_type' => $record['event_type'],
            'payload' => json_encode($record['payload'], \JSON_THROW_ON_ERROR),
            'recorded_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ], [
            'recorded_at' => 'datetime_immutable',
        ]);
    }

    public function readStream(string $executionId): iterable
    {
        foreach ($this->readStreamWithRecordedAt($executionId) as $entry) {
            yield $entry['event'];
        }
    }

    public function readStreamWithRecordedAt(string $executionId): iterable
    {
        $this->schema->ensure();

        $rows = $this->connection->executeQuery(
            \sprintf('SELECT event_type, payload, recorded_at FROM %s WHERE execution_id = ? ORDER BY id ASC', $this->table),
            [$executionId],
        );

        foreach ($rows->iterateAssociative() as $row) {
            yield [
                'event' => EventDataMapper::toDomainEvent([
                    'execution_id' => $executionId,
                    'event_type' => $row['event_type'],
                    'payload' => $row['payload'],
                ]),
                'recordedAt' => self::toDateTime($row['recorded_at']),
            ];
        }
    }

    public function countEventsInStream(string $executionId): int
    {
        $this->schema->ensure();

        return (int) $this->connection->fetchOne(
            \sprintf('SELECT COUNT(*) FROM %s WHERE execution_id = ?', $this->table),
            [$executionId],
        );
    }

    /**
     * Les plateformes rendent `recorded_at` en string (SQLite, MySQL) ou en objet (PostgreSQL selon le driver).
     */
    private static function toDateTime(mixed $raw): ?\DateTimeImmutable
    {
        if ($raw instanceof \DateTimeImmutable) {
            return $raw;
        }
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        return new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
    }
}
