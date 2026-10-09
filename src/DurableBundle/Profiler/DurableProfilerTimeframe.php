<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Profiler;

/**
 * Builds the time bounds of the profiler bars from the process trace timestamps ({@see microtime}).
 *
 * @internal
 */
final class DurableProfilerTimeframe
{
    private function __construct() {}

    private const MIN_SEGMENT_SEC = 1e-6;

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
