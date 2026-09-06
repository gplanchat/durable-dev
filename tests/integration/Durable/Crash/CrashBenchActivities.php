<?php

declare(strict_types=1);

namespace integration\Durable\Crash;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * The two activities the crash bench schedules, and nothing else.
 *
 * Deliberately trivial. The bench measures whether an execution survives the death of the process
 * that started it — not whether an activity computes anything interesting. Anything more here would
 * be a second variable in a measurement that has never been taken once.
 */
interface CrashBenchActivities
{
    /**
     * Runs before the crash, and is the one that must NOT run again after it.
     */
    #[AsActivityMethod('bench.first')]
    public function first(string $tag): string;

    /**
     * Runs after the crash — it is the activity whose start kills the first process.
     */
    #[AsActivityMethod('bench.second')]
    public function second(string $tag): string;
}
