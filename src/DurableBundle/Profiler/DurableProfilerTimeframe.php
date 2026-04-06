<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Profiler;

/**
 * Construit les bornes temporelles des barres du profiler à partir d’horodatages réels
 * ({@see \DateTimeImmutable} côté event store, {@see microtime} côté trace processus).
 */
final class DurableProfilerTimeframe
{
    public const MIN_SEGMENT_SEC = 1e-6;

    /**
     * Horodatages Unix (secondes, précision µs) alignés sur `recorded_at`, avec ordre strictement croissant.
     *
     * @param list<array{recordedAt: \DateTimeImmutable|null}> $entries
     *
     * @return list<float>
     */
    public static function monotonicUnixSecondsFromRecordedEntries(array $entries): array
    {
        $times = [];
        $prev = null;
        foreach ($entries as $entry) {
            $raw = self::unixSecondsFromRecordedAt($entry['recordedAt'] ?? null);
            if (null === $raw) {
                $raw = ($prev ?? 0.0) + self::MIN_SEGMENT_SEC;
            }
            if (null !== $prev && $raw <= $prev) {
                $raw = $prev + 1e-9;
            }
            $times[] = $raw;
            $prev = $raw;
        }

        return $times;
    }

    private static function unixSecondsFromRecordedAt(?\DateTimeImmutable $dt): ?float
    {
        if (null === $dt) {
            return null;
        }

        return (float) $dt->format('U.u');
    }

    /**
     * @return array{startSec: float, endSec: float}
     */
    public static function boundsForProcessTraceEntry(
        float $at,
        ?float $nextAt,
        string $kind,
        float $activityDurationSeconds,
    ): array {
        if ('activity' === $kind) {
            $start = $at - $activityDurationSeconds;
            $end = $at;
            if ($end <= $start) {
                $end = $start + self::MIN_SEGMENT_SEC;
            }

            return ['startSec' => $start, 'endSec' => $end];
        }

        $start = $at;
        if (null !== $nextAt) {
            $end = max($start + self::MIN_SEGMENT_SEC, $nextAt);
        } else {
            $end = $start + self::MIN_SEGMENT_SEC;
        }

        return ['startSec' => $start, 'endSec' => $end];
    }
}
