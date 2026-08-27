<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate\Store;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\Store\EventStoreInterface;
use Illuminate\Database\Connection;

/**
 * Journal d'événements sur la connexion que Laravel possède déjà.
 *
 * C'est le point de tout le pont, et pas seulement une commodité d'idiome : DUR030 vend l'exécution
 * durable sur **une** base sans cluster, ce qui ne paie que si l'ajout au journal et l'écriture
 * métier tombent dans la même transaction. Une activité qui écrit par Eloquent pendant qu'un
 * journal Doctrine écrit par un second PDO, ce sont deux portées transactionnelles : le processus
 * meurt entre les deux, le replay rejoue l'activité, et la garantie qu'on annonçait n'a jamais
 * existé. Ici le store est sur `DB::connection()`, donc `DB::transaction()` referme sur les deux.
 *
 * La (dé)sérialisation passe entièrement par {@see EventDataMapper} : les lignes ont la même forme
 * que celles du pont DBAL et que les enregistrements du journal Temporal. Ce n'est pas une
 * convention d'écriture mais une exigence prouvée — les deux ponts rejouent
 * {@see \Gplanchat\Durable\Testing\EventStoreConformanceTestCase}, dont le cas de fidélité compare
 * l'enregistrement relu à l'enregistrement écrit sur les vingt-trois types que le mapper connaît.
 *
 * ponytail: pas de colonne `sequence` — l'auto-increment porte l'ordre d'insertion, comme côté
 * DBAL. L'exclusion mutuelle entre deux reprises concurrentes d'une même exécution est en amont,
 * dans la file : côté Laravel c'est `WithoutOverlapping` ou un verrou de cache atomique, et aucun
 * choix de stockage ne la fournit.
 *
 * @see DUR030
 * @see DUR041
 */
final class IlluminateEventStore implements EventStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_events',
    ) {}

    public function append(Event $event): void
    {
        $this->schema->ensure();

        $record = EventDataMapper::fromDomainEvent($event);

        $this->connection->table($this->table)->insert([
            'execution_id' => $record['execution_id'],
            'event_type' => $record['event_type'],
            'payload' => json_encode($record['payload'], \JSON_THROW_ON_ERROR),
            'recorded_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s'),
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

        // `cursor()` plutôt que `get()` : le flux se lit une fois, sans matérialiser une exécution
        // longue en mémoire. Un second appel repart d'une nouvelle requête, ce que la conformité
        // exige explicitement.
        $rows = $this->connection->table($this->table)
            ->select(['event_type', 'payload', 'recorded_at'])
            ->where('execution_id', $executionId)
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            yield [
                'event' => EventDataMapper::toDomainEvent([
                    'execution_id' => $executionId,
                    'event_type' => $row->event_type,
                    'payload' => $row->payload,
                ]),
                'recordedAt' => self::toDateTime($row->recorded_at),
            ];
        }
    }

    public function countEventsInStream(string $executionId): int
    {
        $this->schema->ensure();

        return $this->connection->table($this->table)
            ->where('execution_id', $executionId)
            ->count();
    }

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
