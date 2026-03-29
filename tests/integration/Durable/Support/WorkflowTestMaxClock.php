<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Support;

/**
 * Horloge pour les tests E2E : tous les timers sont considérés comme dus dans {@see \Gplanchat\Durable\ExecutionRuntime::checkTimers}.
 */
final class WorkflowTestMaxClock
{
    public function __invoke(): float
    {
        return \PHP_FLOAT_MAX;
    }
}
