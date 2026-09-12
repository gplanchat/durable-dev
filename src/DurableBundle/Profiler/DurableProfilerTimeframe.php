<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Profiler;

/**
 * Builds the time bounds of the profiler bars from real timestamps
 * ({@see \DateTimeImmutable} on the event store side, {@see microtime} on the process trace side).
 */
final class DurableProfilerTimeframe
{
    public const MIN_SEGMENT_SEC = 1e-6;

    /**
     * Unix timestamps (seconds, µs precision) aligned on `recorded_at`, with a strictly increasing order.
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
